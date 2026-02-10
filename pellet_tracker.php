<?php
/**
 * ETA Pellet Verbrauch Tracker
 * Liest den Pelletverbrauch via ETA RESTful API und loggt in eine TXT-Datei.
 */

// ── Konfiguration ──────────────────────────────────────────────
define('ETA_IP', '192.168.88.36');
define('ETA_PORT', 8080);
define('LOG_FILE', __DIR__ . '/pellet_verbrauch.txt');
define('CONFIG_FILE', __DIR__ . '/config.json');

// Standard-Konfiguration (wird verwendet wenn keine config.json existiert)
define('DEFAULT_CONFIG', json_encode([
    'hero' => [
        'uri'  => '/40/10201/0/0/12015',
        'name' => 'Lager Vorrat',
    ],
    'tiles' => [
        ['uri' => '/40/10021/0/0/12016', 'name' => 'Gesamtverbrauch'],
        ['uri' => '/40/10021/0/0/12011', 'name' => 'Inhalt Pelletsbehälter'],
        ['uri' => '/40/10021/0/0/12014', 'name' => 'Verbrauch seit Wartung'],
        ['uri' => '/40/10021/0/0/12012', 'name' => 'Verbrauch seit Entaschung'],
        ['uri' => '/40/10021/0/0/12013', 'name' => 'Verbrauch seit Aschebox leeren'],
        ['uri' => '/40/10021/0/0/12153', 'name' => 'Volllaststunden'],
        ['uri' => '/120/10221/0/0/12197', 'name' => 'Aussentemperatur (Solar)'],
    ],
]));

/**
 * Konfiguration laden (aus config.json oder Defaults)
 */
function load_config(): array {
    if (file_exists(CONFIG_FILE)) {
        $json = file_get_contents(CONFIG_FILE);
        $config = json_decode($json, true);
        if (is_array($config) && isset($config['hero'], $config['tiles'])) {
            return $config;
        }
    }
    return json_decode(DEFAULT_CONFIG, true);
}

/**
 * Konfiguration speichern
 */
function save_config(array $config): bool {
    return file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Konfiguration laden
$CONFIG = load_config();
// ────────────────────────────────────────────────────────────────

/**
 * HTTP GET Request an die ETA API (curl mit file_get_contents Fallback)
 */
function eta_fetch(string $path): ?string {
    $url = 'http://' . ETA_IP . ':' . ETA_PORT . $path;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response !== false && $code === 200) return $response;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $ctx);
    return $response !== false ? $response : null;
}

/**
 * XML parsen - Namespace wird entfernt fuer einfacheren Zugriff
 */
function parse_xml(string $xmlStr): ?SimpleXMLElement {
    $xmlStr = preg_replace('/\sxmlns="[^"]*"/', '', $xmlStr);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlStr);
    return $xml ?: null;
}

/**
 * Liest eine einzelne Variable von der ETA API
 */
function read_variable(string $uri): ?array {
    $response = eta_fetch('/user/var' . $uri);
    if ($response === null) return null;
    $xml = parse_xml($response);
    if ($xml === null) return null;
    $values = $xml->xpath('//value');
    if (empty($values)) return null;
    $val = $values[0];
    return [
        'uri'       => (string)($val['uri'] ?? ''),
        'strValue'  => (string)($val['strValue'] ?? ''),
        'unit'      => (string)($val['unit'] ?? ''),
        'decPlaces' => (int)($val['decPlaces'] ?? 0),
        'scaleFactor' => (int)($val['scaleFactor'] ?? 1),
        'rawValue'  => trim((string)$val),
    ];
}

/**
 * Liest den Menubaum
 */
function read_menu(): ?SimpleXMLElement {
    $response = eta_fetch('/user/menu');
    if ($response === null) return null;
    return parse_xml($response);
}

/**
 * Schreibt einen Eintrag in die Log-Datei
 */
function log_value(string $uri, string $name, string $strValue, string $unit, string $rawValue, string $source = 'web'): void {
    $timestamp = date('Y-m-d H:i:s');
    $line = "$timestamp\t$name\t$strValue\t$unit\t$rawValue\t$uri\t$source\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Liest die letzten N Eintraege aus der Log-Datei
 */
function read_log(int $limit = 50): array {
    if (!file_exists(LOG_FILE)) return [];
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);
    $entries = [];
    foreach (array_slice($lines, 0, $limit) as $line) {
        $parts = explode("\t", $line);
        if (count($parts) >= 5) {
            $entries[] = [
                'timestamp' => $parts[0] ?? '',
                'name'      => $parts[1] ?? '',
                'strValue'  => $parts[2] ?? '',
                'unit'      => $parts[3] ?? '',
                'rawValue'  => $parts[4] ?? '',
                'uri'       => $parts[5] ?? '',
                'source'    => $parts[6] ?? '',
            ];
        }
    }
    return $entries;
}

/**
 * Berechnet den Verbrauch aus den Lager-Vorrat-Logdaten.
 * Verbrauch = Rueckgang des Vorrats. Anstieg = Befuellung (wird ignoriert).
 * Gibt taeglich/woechentlich/monatlich/jaehrlich aggregierte Daten zurueck.
 */
function calc_consumption(string $heroUri): array {
    if (!file_exists(LOG_FILE)) return ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'stock'=>[]];

    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Alle Lager-Vorrat-Eintraege chronologisch sammeln
    $readings = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        $uri = trim($parts[5] ?? '');
        if ($uri !== $heroUri) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false) continue;
        $raw = floatval($parts[4]);
        $readings[] = ['ts' => $ts, 'value' => $raw];
    }

    if (count($readings) < 2) return ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'stock'=>$readings];

    // Verbrauch zwischen aufeinanderfolgenden Messungen berechnen
    $daily   = [];
    $weekly  = [];
    $monthly = [];
    $yearly  = [];

    for ($i = 1; $i < count($readings); $i++) {
        $prev = $readings[$i - 1];
        $curr = $readings[$i];
        $diff = $prev['value'] - $curr['value'];

        // Nur Verbrauch zaehlen (positiver Diff = Vorrat gesunken)
        if ($diff <= 0) continue;

        $day   = date('Y-m-d', $curr['ts']);
        $week  = date('o-\KW', $curr['ts']);
        $month = date('Y-m', $curr['ts']);
        $year  = date('Y', $curr['ts']);

        $daily[$day]     = ($daily[$day] ?? 0) + $diff;
        $weekly[$week]   = ($weekly[$week] ?? 0) + $diff;
        $monthly[$month] = ($monthly[$month] ?? 0) + $diff;
        $yearly[$year]   = ($yearly[$year] ?? 0) + $diff;
    }

    // Auf ganze kg runden
    foreach ($daily as &$v)   $v = round($v);
    foreach ($weekly as &$v)  $v = round($v);
    foreach ($monthly as &$v) $v = round($v);
    foreach ($yearly as &$v)  $v = round($v);

    // Letzte N Eintraege behalten
    $daily   = array_slice($daily, -30, null, true);
    $weekly  = array_slice($weekly, -12, null, true);
    $monthly = array_slice($monthly, -12, null, true);
    $yearly  = array_slice($yearly, -5, null, true);

    return compact('daily', 'weekly', 'monthly', 'yearly') + ['stock' => $readings];
}

/**
 * Rekursiv Menubaum als HTML rendern (mit + Buttons)
 */
function render_objects(SimpleXMLElement $parent, bool $showAddButtons = false): string {
    $html = '';
    $objects = $parent->object ?? [];
    $hasChildren = false;
    foreach ($objects as $obj) {
        if (!$hasChildren) { $html .= '<ul>'; $hasChildren = true; }
        $uri  = (string)($obj['uri'] ?? '');
        $name = (string)($obj['name'] ?? '');
        $html .= '<li>';
        $html .= '<a href="?action=dashboard&uri=' . urlencode($uri) . '">'
               . htmlspecialchars($name) . '</a>';
        $html .= ' <small style="color:#666;">' . htmlspecialchars($uri) . '</small>';
        if ($showAddButtons && $uri) {
            $html .= ' <a href="?action=addtile&uri=' . urlencode($uri) . '&name=' . urlencode($name)
                   . '" class="btn-add" title="Als Kachel hinzufuegen">+</a>';
            $html .= ' <a href="?action=sethero&uri=' . urlencode($uri) . '&name=' . urlencode($name)
                   . '" class="btn-star" title="Als Hero setzen">&#9733;</a>';
        }
        $html .= render_objects($obj, $showAddButtons);
        $html .= '</li>';
    }
    if ($hasChildren) $html .= '</ul>';
    return $html;
}

// ── Request-Handling ────────────────────────────────────────────
$action  = $_GET['action'] ?? 'dashboard';
$varUri  = $_GET['uri'] ?? $CONFIG['hero']['uri'];
$message = '';
$error   = '';

// Kachel hinzufuegen (aus Menubaum)
if ($action === 'addtile' && isset($_GET['uri'], $_GET['name'])) {
    $newUri  = $_GET['uri'];
    $newName = $_GET['name'];
    // Pruefen ob bereits vorhanden (als Hero oder Tile)
    $exists = ($CONFIG['hero']['uri'] === $newUri);
    foreach ($CONFIG['tiles'] as $t) {
        if ($t['uri'] === $newUri) { $exists = true; break; }
    }
    if ($exists) {
        $error = "Variable ist bereits auf dem Dashboard: $newName";
    } else {
        $CONFIG['tiles'][] = ['uri' => $newUri, 'name' => $newName];
        save_config($CONFIG);
        $message = "Kachel hinzugefuegt: $newName";
    }
    $action = 'dashboard';
}

// Kachel entfernen
if ($action === 'deltile' && isset($_GET['idx'])) {
    $idx = (int)$_GET['idx'];
    if (isset($CONFIG['tiles'][$idx])) {
        $removed = $CONFIG['tiles'][$idx]['name'];
        array_splice($CONFIG['tiles'], $idx, 1);
        save_config($CONFIG);
        $message = "Kachel entfernt: $removed";
    }
    $action = 'dashboard';
}

// Hero-Variable setzen (aus Menubaum)
if ($action === 'sethero' && isset($_GET['uri'], $_GET['name'])) {
    $newUri  = $_GET['uri'];
    $newName = $_GET['name'];
    // Alte Hero in Tiles verschieben, falls nicht schon drin
    $oldHero = $CONFIG['hero'];
    $alreadyTile = false;
    foreach ($CONFIG['tiles'] as $t) {
        if ($t['uri'] === $oldHero['uri']) { $alreadyTile = true; break; }
    }
    if (!$alreadyTile) {
        $CONFIG['tiles'][] = $oldHero;
    }
    // Neue Hero aus Tiles entfernen falls vorhanden
    $CONFIG['tiles'] = array_values(array_filter($CONFIG['tiles'], function($t) use ($newUri) {
        return $t['uri'] !== $newUri;
    }));
    $CONFIG['hero'] = ['uri' => $newUri, 'name' => $newName];
    save_config($CONFIG);
    $message = "Hero gesetzt: $newName";
    $action = 'dashboard';
}

// Konfiguration zuruecksetzen
if ($action === 'reset') {
    $CONFIG = json_decode(DEFAULT_CONFIG, true);
    save_config($CONFIG);
    $message = "Dashboard auf Standard zurueckgesetzt.";
    $action = 'settings';
}

// Einstellungen speichern (URI-Bearbeitung)
if ($action === 'savesettings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $heroUri  = trim($_POST['hero_uri'] ?? '');
    $heroName = trim($_POST['hero_name'] ?? '');
    if ($heroUri && $heroName) {
        $CONFIG['hero'] = ['uri' => $heroUri, 'name' => $heroName];
    }
    $tileUris  = $_POST['tile_uri'] ?? [];
    $tileNames = $_POST['tile_name'] ?? [];
    $newTiles = [];
    for ($i = 0; $i < count($tileUris); $i++) {
        $u = trim($tileUris[$i] ?? '');
        $n = trim($tileNames[$i] ?? '');
        if ($u && $n) {
            $newTiles[] = ['uri' => $u, 'name' => $n];
        }
    }
    $CONFIG['tiles'] = $newTiles;
    save_config($CONFIG);
    $message = "Einstellungen gespeichert.";
    $action = 'settings';
}

// Alle Variablen abrufen + loggen
if ($action === 'fetchall') {
    $count = 0;
    // Hero-Variable zuerst
    $data = read_variable($CONFIG['hero']['uri']);
    if ($data) {
        log_value($CONFIG['hero']['uri'], $CONFIG['hero']['name'], $data['strValue'], $data['unit'], $data['rawValue']);
        $count++;
    }
    foreach ($CONFIG['tiles'] as $tile) {
        $data = read_variable($tile['uri']);
        if ($data) {
            log_value($tile['uri'], $tile['name'], $data['strValue'], $data['unit'], $data['rawValue']);
            $count++;
        }
    }
    $message = "$count Variablen erfolgreich abgerufen und geloggt.";
    $action = 'dashboard';
}

// Einzelne Variable abrufen + loggen
if ($action === 'fetch') {
    $data = read_variable($varUri);
    if ($data) {
        $name = $varUri;
        if ($CONFIG['hero']['uri'] === $varUri) $name = $CONFIG['hero']['name'];
        foreach ($CONFIG['tiles'] as $t) {
            if ($t['uri'] === $varUri) { $name = $t['name']; break; }
        }
        log_value($varUri, $name, $data['strValue'], $data['unit'], $data['rawValue']);
        $message = "Wert geloggt: {$data['strValue']} {$data['unit']}";
    } else {
        $error = "Fehler beim Abrufen: $varUri";
    }
    $action = 'dashboard';
}

// Dashboard: Hero + alle konfigurierten Variablen lesen
$heroData = null;
$dashboardData = [];
if ($action === 'dashboard') {
    $heroData = read_variable($CONFIG['hero']['uri']);
    foreach ($CONFIG['tiles'] as $idx => $tile) {
        $data = read_variable($tile['uri']);
        $dashboardData[$idx] = [
            'uri'  => $tile['uri'],
            'name' => $tile['name'],
            'data' => $data,
        ];
    }
}

// Verbrauch berechnen
$consumption = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'stock'=>[]];
if ($action === 'verbrauch') {
    $consumption = calc_consumption($CONFIG['hero']['uri']);
}

// Menubaum laden
$menuXml = null;
if ($action === 'menu') {
    $menuXml = read_menu();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ETA Pellet Tracker</title>
    <?php if($action==='verbrauch'):?><script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script><?php endif?>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#1a1a2e;color:#eee;min-height:100vh;-webkit-text-size-adjust:100%}
        .container{max-width:960px;margin:0 auto;padding:16px}
        h1{color:#e94560;margin-bottom:4px;font-size:1.5em}
        .subtitle{color:#888;margin-bottom:16px;font-size:.85em}
        nav{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
        nav a{padding:8px 14px;background:#16213e;color:#eee;text-decoration:none;border-radius:6px;font-size:.9em;white-space:nowrap}
        nav a:hover,nav a.active{background:#e94560}
        .card{background:#16213e;border-radius:10px;padding:16px;margin-bottom:16px}
        .card h2{color:#e94560;margin-bottom:12px;font-size:1.1em}
        .hero-card{background:linear-gradient(135deg,#0f3460,#16213e);border-radius:12px;padding:24px 16px;margin-bottom:16px;text-align:center;border:2px solid #e94560;position:relative}
        .hero-card .hero-label{color:#95d5b2;font-size:1em;margin-bottom:6px;text-transform:uppercase;letter-spacing:2px}
        .hero-card .hero-value{font-size:3.5em;font-weight:bold;color:#e94560;line-height:1.1}
        .hero-card .hero-unit{font-size:.35em;color:#888;margin-left:6px}
        .hero-card .hero-uri{font-size:.7em;color:#555;margin-top:6px;font-family:monospace}
        .var-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
        .var-card{background:#0f3460;border-radius:8px;padding:10px;position:relative}
        .var-card .var-name{color:#95d5b2;font-size:.75em;margin-bottom:3px;padding-right:20px}
        .var-card .var-value{font-size:1.3em;font-weight:bold;color:#e94560}
        .var-card .var-unit{font-size:.6em;color:#888;margin-left:3px}
        .var-card .var-uri{font-size:.6em;color:#555;margin-top:3px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .btn-del{position:absolute;top:6px;right:8px;color:#666;text-decoration:none;font-size:1.1em;line-height:1;width:20px;height:20px;display:flex;align-items:center;justify-content:center;border-radius:50%}
        .btn-del:hover{color:#e94560;background:rgba(233,69,96,0.15)}
        .btn{display:inline-block;padding:10px 20px;background:#e94560;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:1em;text-decoration:none;margin:5px 5px 5px 0;-webkit-tap-highlight-color:transparent}
        .btn:hover,.btn:active{background:#c73e54}
        .btn-sm{padding:6px 12px;font-size:.85em}
        .btn-outline{background:transparent;border:1px solid #e94560;color:#e94560}
        .btn-outline:hover,.btn-outline:active{background:#e94560;color:#fff}
        .msg{padding:10px;border-radius:6px;margin-bottom:12px;font-size:.9em}
        .msg.success{background:#1b4332;color:#95d5b2}
        .msg.error{background:#4a1525;color:#f5a0a0}
        table{width:100%;border-collapse:collapse}
        th,td{padding:6px 8px;text-align:left;border-bottom:1px solid #0f3460;font-size:.85em}
        th{color:#e94560;font-size:.75em;text-transform:uppercase}
        .table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
        .menu-tree{font-size:.85em}
        .menu-tree ul{list-style:none;padding-left:16px}
        .menu-tree>ul{padding-left:0}
        .menu-tree li{padding:4px 0}
        .menu-tree a{color:#e94560;text-decoration:none;padding:2px 0;display:inline-block}
        .menu-tree a:hover,.menu-tree a:active{text-decoration:underline}
        .menu-tree .fub-name{color:#95d5b2;font-weight:bold}
        .btn-add{display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;background:#1b4332;color:#95d5b2;border-radius:50%;font-size:.9em;text-decoration:none;margin-left:4px;vertical-align:middle}
        .btn-add:hover{background:#95d5b2;color:#1a1a2e;text-decoration:none}
        .btn-star{display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;background:#4a3800;color:#f0c040;border-radius:50%;font-size:.7em;text-decoration:none;margin-left:2px;vertical-align:middle}
        .btn-star:hover{background:#f0c040;color:#1a1a2e;text-decoration:none}
        .err{color:#f5a0a0}
        .chart-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
        .chart-tabs button{padding:8px 14px;background:#0f3460;color:#eee;border:none;border-radius:6px;cursor:pointer;font-size:.85em;-webkit-tap-highlight-color:transparent}
        .chart-tabs button.active{background:#e94560}
        .chart-tabs button:hover,.chart-tabs button:active{background:#e94560}
        .chart-wrap{position:relative;height:250px}
        .chart-wrap canvas{display:none}
        .chart-wrap canvas.active{display:block}
        .consumption-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
        .cons-item{background:#0f3460;border-radius:8px;padding:8px 4px;text-align:center}
        .cons-item .cons-label{color:#888;font-size:.65em;text-transform:uppercase}
        .cons-item .cons-val{font-size:1.3em;font-weight:bold;color:#e94560;margin-top:2px}
        .cons-item .cons-unit{font-size:.55em;color:#888}
        .no-data{color:#888;text-align:center;padding:30px 0;font-size:.9em}
        /* Settings */
        .settings-form label{display:block;color:#95d5b2;font-size:.8em;margin-bottom:3px;margin-top:12px}
        .settings-form input[type="text"]{width:100%;padding:8px 10px;background:#0f3460;color:#eee;border:1px solid #333;border-radius:6px;font-size:.9em;font-family:inherit}
        .settings-form input[type="text"]:focus{outline:none;border-color:#e94560}
        .tile-row{display:grid;grid-template-columns:1fr 2fr 30px;gap:8px;align-items:end;margin-bottom:6px}
        .tile-row .btn-del-inline{color:#666;text-decoration:none;font-size:1.2em;text-align:center;line-height:36px}
        .tile-row .btn-del-inline:hover{color:#e94560}
        .settings-section{border-top:1px solid #0f3460;padding-top:16px;margin-top:16px}
        /* Tablet */
        @media(min-width:600px){
            .container{padding:20px}
            h1{font-size:1.8em}
            .hero-card{padding:30px}
            .hero-card .hero-value{font-size:5em}
            .var-grid{grid-template-columns:repeat(3,1fr);gap:12px}
            .var-card .var-value{font-size:1.5em}
            .chart-wrap{height:300px}
            .consumption-summary{gap:10px}
            .cons-item .cons-val{font-size:1.6em}
            th,td{padding:8px 12px;font-size:.9em}
            .tile-row{grid-template-columns:1fr 2.5fr 30px}
        }
        /* Desktop */
        @media(min-width:900px){
            .hero-card .hero-value{font-size:6em}
            .var-grid{grid-template-columns:repeat(4,1fr)}
            .chart-wrap{height:350px}
        }
    </style>
</head>
<body>
<div class="container">
    <h1>ETA Pellet Tracker</h1>
    <p class="subtitle">Kessel: <?=htmlspecialchars(ETA_IP)?>:<?=ETA_PORT?></p>

    <nav>
        <a href="?action=dashboard" class="<?=$action==='dashboard'?'active':''?>">Dashboard</a>
        <a href="?action=verbrauch" class="<?=$action==='verbrauch'?'active':''?>">Verbrauch</a>
        <a href="?action=log" class="<?=$action==='log'?'active':''?>">Log</a>
        <a href="?action=menu" class="<?=$action==='menu'?'active':''?>">Menubaum</a>
        <a href="?action=settings" class="<?=$action==='settings'?'active':''?>">Einstellungen</a>
    </nav>

    <?php if($message):?><div class="msg success"><?=htmlspecialchars($message)?></div><?php endif?>
    <?php if($error):?><div class="msg error"><?=htmlspecialchars($error)?></div><?php endif?>

    <?php if($action==='dashboard'):?>

        <!-- Hero -->
        <div class="hero-card">
            <div class="hero-label"><?=htmlspecialchars($CONFIG['hero']['name'])?></div>
            <?php if($heroData):?>
                <div class="hero-value">
                    <?=htmlspecialchars($heroData['strValue'])?>
                    <span class="hero-unit"><?=htmlspecialchars($heroData['unit'])?></span>
                </div>
            <?php else:?>
                <div class="hero-value err">--</div>
            <?php endif?>
            <div class="hero-uri"><?=htmlspecialchars($CONFIG['hero']['uri'])?></div>
        </div>

        <!-- Kacheln -->
        <div class="card">
            <h2>Details</h2>
            <?php if(!empty($dashboardData)):?>
                <div class="var-grid">
                <?php foreach($dashboardData as $idx => $item):?>
                    <div class="var-card">
                        <a href="?action=deltile&idx=<?=$idx?>" class="btn-del" title="Kachel entfernen" onclick="return confirm('Kachel entfernen?')">&times;</a>
                        <div class="var-name"><?=htmlspecialchars($item['name'])?></div>
                        <?php if($item['data']):?>
                            <div class="var-value">
                                <?=htmlspecialchars($item['data']['strValue'])?>
                                <span class="var-unit"><?=htmlspecialchars($item['data']['unit'])?></span>
                            </div>
                        <?php else:?>
                            <div class="var-value err">--</div>
                        <?php endif?>
                        <div class="var-uri"><?=htmlspecialchars($item['uri'])?></div>
                    </div>
                <?php endforeach?>
                </div>
                <br>
                <a href="?action=fetchall" class="btn">Alle abrufen &amp; loggen</a>
            <?php else:?>
                <p style="color:#888">Keine Kacheln konfiguriert. <a href="?action=menu" style="color:#e94560">Im Menubaum hinzufuegen</a> oder <a href="?action=reset" style="color:#e94560">Standard wiederherstellen</a>.</p>
            <?php endif?>
        </div>

    <?php elseif($action==='verbrauch'):?>

        <!-- Verbrauch -->
        <div class="card">
            <h2>Pelletverbrauch</h2>
            <?php
                $hasData = !empty($consumption['daily']) || !empty($consumption['weekly'])
                        || !empty($consumption['monthly']) || !empty($consumption['yearly']);
            ?>
            <?php if($hasData):?>
                <?php
                    $today     = date('Y-m-d');
                    $thisWeek  = date('o-\KW');
                    $thisMonth = date('Y-m');
                    $thisYear  = date('Y');
                    $consToday = $consumption['daily'][$today] ?? 0;
                    $consWeek  = $consumption['weekly'][$thisWeek] ?? 0;
                    $consMonth = $consumption['monthly'][$thisMonth] ?? 0;
                    $consYear  = $consumption['yearly'][$thisYear] ?? 0;
                ?>
                <div class="consumption-summary">
                    <div class="cons-item">
                        <div class="cons-label">Heute</div>
                        <div class="cons-val"><?=$consToday?> <span class="cons-unit">kg</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Diese Woche</div>
                        <div class="cons-val"><?=$consWeek?> <span class="cons-unit">kg</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Dieser Monat</div>
                        <div class="cons-val"><?=$consMonth?> <span class="cons-unit">kg</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Dieses Jahr</div>
                        <div class="cons-val"><?=$consYear?> <span class="cons-unit">kg</span></div>
                    </div>
                </div>

                <div class="chart-tabs">
                    <button class="active" onclick="showChart('daily',this)">Taeglich</button>
                    <button onclick="showChart('weekly',this)">Woechentlich</button>
                    <button onclick="showChart('monthly',this)">Monatlich</button>
                    <button onclick="showChart('yearly',this)">Jaehrlich</button>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-daily" class="active"></canvas>
                    <canvas id="chart-weekly"></canvas>
                    <canvas id="chart-monthly"></canvas>
                    <canvas id="chart-yearly"></canvas>
                </div>

                <script>
                const chartData = {
                    daily:   {labels:<?=json_encode(array_keys($consumption['daily']))?>,   data:<?=json_encode(array_values($consumption['daily']))?>},
                    weekly:  {labels:<?=json_encode(array_keys($consumption['weekly']))?>,  data:<?=json_encode(array_values($consumption['weekly']))?>},
                    monthly: {labels:<?=json_encode(array_keys($consumption['monthly']))?>, data:<?=json_encode(array_values($consumption['monthly']))?>},
                    yearly:  {labels:<?=json_encode(array_keys($consumption['yearly']))?>,  data:<?=json_encode(array_values($consumption['yearly']))?>}
                };
                const charts = {};
                function makeChart(id, labels, data) {
                    const ctx = document.getElementById('chart-'+id).getContext('2d');
                    charts[id] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Verbrauch (kg)',
                                data: data,
                                backgroundColor: 'rgba(233,69,96,0.7)',
                                borderColor: '#e94560',
                                borderWidth: 1,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {display: false},
                                tooltip: {
                                    callbacks: {
                                        label: function(ctx) { return ctx.parsed.y + ' kg'; }
                                    }
                                }
                            },
                            scales: {
                                x: {ticks:{color:'#888',maxRotation:45},grid:{color:'rgba(255,255,255,0.05)'}},
                                y: {beginAtZero:true,ticks:{color:'#888',callback:function(v){return v+' kg'}},grid:{color:'rgba(255,255,255,0.08)'}}
                            }
                        }
                    });
                }
                function showChart(id, btn) {
                    document.querySelectorAll('.chart-tabs button').forEach(b=>b.classList.remove('active'));
                    document.querySelectorAll('.chart-wrap canvas').forEach(c=>c.classList.remove('active'));
                    btn.classList.add('active');
                    document.getElementById('chart-'+id).classList.add('active');
                    if (!charts[id]) makeChart(id, chartData[id].labels, chartData[id].data);
                }
                if (chartData.daily.labels.length > 0) {
                    makeChart('daily', chartData.daily.labels, chartData.daily.data);
                }
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch keine Verbrauchsdaten vorhanden.<br>
                    <small>Der Cronjob muss mindestens 2x gelaufen sein, damit ein Verbrauch berechnet werden kann.</small>
                </div>
            <?php endif?>
        </div>

    <?php elseif($action==='log'):?>

        <div class="card">
            <h2>Letzte Eintraege</h2>
            <?php $entries = read_log(200);?>
            <?php if($entries):?>
                <div class="table-scroll"><table>
                    <thead><tr><th>Zeitpunkt</th><th>Variable</th><th>Wert</th><th>Einheit</th><th>Rohwert</th><th>Quelle</th></tr></thead>
                    <tbody>
                    <?php foreach($entries as $e):?>
                        <tr>
                            <td><?=htmlspecialchars($e['timestamp'])?></td>
                            <td><?=htmlspecialchars($e['name'])?></td>
                            <td><?=htmlspecialchars($e['strValue'])?></td>
                            <td><?=htmlspecialchars($e['unit'])?></td>
                            <td><?=htmlspecialchars($e['rawValue'])?></td>
                            <td><?=htmlspecialchars($e['source'])?></td>
                        </tr>
                    <?php endforeach?>
                    </tbody>
                </table></div>
            <?php else:?>
                <p style="color:#888">Noch keine Eintraege vorhanden.</p>
            <?php endif?>
        </div>

    <?php elseif($action==='menu'):?>

        <div class="card">
            <h2>ETA Menubaum</h2>
            <p style="color:#888;margin-bottom:15px">
                <span style="color:#95d5b2;font-weight:bold">+</span> = Kachel hinzufuegen &nbsp;
                <span style="color:#f0c040;font-weight:bold">&#9733;</span> = Als Hero setzen &nbsp;
                Klick = Im Dashboard oeffnen
            </p>
            <?php if($menuXml):?>
                <div class="menu-tree">
                <ul>
                <?php foreach($menuXml->menu->fub ?? [] as $fub):
                    $fubUri  = (string)($fub['uri'] ?? '');
                    $fubName = (string)($fub['name'] ?? '');
                ?>
                    <li>
                        <span class="fub-name"><?=htmlspecialchars($fubName)?></span>
                        <small style="color:#666"><?=htmlspecialchars($fubUri)?></small>
                        <?=render_objects($fub, true)?>
                    </li>
                <?php endforeach?>
                </ul>
                </div>
            <?php else:?>
                <p class="err">Menubaum konnte nicht geladen werden.</p>
            <?php endif?>
        </div>

    <?php elseif($action==='settings'):?>

        <div class="card">
            <h2>Einstellungen</h2>
            <form method="post" action="?action=savesettings" class="settings-form">
                <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:4px">Hero-Variable</h3>
                <div class="tile-row" style="grid-template-columns:1fr 2fr">
                    <div>
                        <label>Name</label>
                        <input type="text" name="hero_name" value="<?=htmlspecialchars($CONFIG['hero']['name'])?>">
                    </div>
                    <div>
                        <label>URI-Pfad</label>
                        <input type="text" name="hero_uri" value="<?=htmlspecialchars($CONFIG['hero']['uri'])?>">
                    </div>
                </div>

                <div class="settings-section">
                    <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:8px">Kacheln</h3>
                    <div id="tiles-list">
                    <?php foreach($CONFIG['tiles'] as $i => $tile):?>
                        <div class="tile-row">
                            <div>
                                <?php if($i===0):?><label>Name</label><?php endif?>
                                <input type="text" name="tile_name[]" value="<?=htmlspecialchars($tile['name'])?>">
                            </div>
                            <div>
                                <?php if($i===0):?><label>URI-Pfad</label><?php endif?>
                                <input type="text" name="tile_uri[]" value="<?=htmlspecialchars($tile['uri'])?>">
                            </div>
                            <a href="#" class="btn-del-inline" onclick="this.parentElement.remove();return false">&times;</a>
                        </div>
                    <?php endforeach?>
                    </div>
                    <a href="#" class="btn btn-sm btn-outline" onclick="addTileRow();return false">+ Kachel hinzufuegen</a>
                </div>

                <div class="settings-section" style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="submit" class="btn">Speichern</button>
                    <a href="?action=reset" class="btn btn-outline" onclick="return confirm('Dashboard auf Standard zuruecksetzen?')">Auf Standard zuruecksetzen</a>
                </div>
            </form>
        </div>

        <script>
        function addTileRow() {
            const list = document.getElementById('tiles-list');
            const row = document.createElement('div');
            row.className = 'tile-row';
            row.innerHTML = '<div><input type="text" name="tile_name[]" placeholder="Name"></div>'
                + '<div><input type="text" name="tile_uri[]" placeholder="/node/fub/fkt/io/var"></div>'
                + '<a href="#" class="btn-del-inline" onclick="this.parentElement.remove();return false">&times;</a>';
            list.appendChild(row);
        }
        </script>

    <?php endif?>
</div>
</body>
</html>
