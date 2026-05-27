<?php
/**
 * ETA Pellet Verbrauch Tracker
 * Liest den Pelletverbrauch via ETA RESTful API und loggt in eine TXT-Datei.
 */

// ── Konfiguration ──────────────────────────────────────────────
define('LOG_FILE', __DIR__ . '/pellet_verbrauch.txt');
define('CONFIG_FILE', __DIR__ . '/config.json');

define('DEFAULT_CONFIG', json_encode([
    'eta_ip'   => '192.168.88.36',
    'eta_port' => 8080,
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
    ],
    'solar' => [
        ['uri' => '/120/10221/0/0/12275', 'name' => 'Kollektor'],
        ['uri' => '/120/10221/0/0/12197', 'name' => 'Außentemperatur'],
        ['uri' => '/120/10251/0/0/12242', 'name' => 'Puffer oben'],
        ['uri' => '/120/10251/0/0/12244', 'name' => 'Puffer unten'],
    ],
]));

function load_config(): array {
    $defaults = json_decode(DEFAULT_CONFIG, true);
    if (file_exists(CONFIG_FILE)) {
        $json = file_get_contents(CONFIG_FILE);
        $config = json_decode($json, true);
        if (is_array($config) && isset($config['hero'], $config['tiles'])) {
            if (!isset($config['eta_ip']))   $config['eta_ip']   = $defaults['eta_ip'];
            if (!isset($config['eta_port'])) $config['eta_port'] = $defaults['eta_port'];
            if (!isset($config['solar']))    $config['solar']    = $defaults['solar'];
            return $config;
        }
    }
    return $defaults;
}

function save_config(array $config): bool {
    return file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$CONFIG = load_config();
// ────────────────────────────────────────────────────────────────

function eta_fetch(string $path): ?string {
    global $CONFIG;
    $url = 'http://' . $CONFIG['eta_ip'] . ':' . $CONFIG['eta_port'] . $path;
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

function parse_xml(string $xmlStr): ?SimpleXMLElement {
    $xmlStr = preg_replace('/\sxmlns="[^"]*"/', '', $xmlStr);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlStr);
    return $xml ?: null;
}

function read_variable(string $uri): ?array {
    $response = eta_fetch('/user/var' . $uri);
    if ($response === null) return null;
    $xml = parse_xml($response);
    if ($xml === null) return null;
    $values = $xml->xpath('//value');
    if (empty($values)) return null;
    $val = $values[0];
    return [
        'uri'         => (string)($val['uri'] ?? ''),
        'strValue'    => (string)($val['strValue'] ?? ''),
        'unit'        => (string)($val['unit'] ?? ''),
        'decPlaces'   => (int)($val['decPlaces'] ?? 0),
        'scaleFactor' => (int)($val['scaleFactor'] ?? 1),
        'rawValue'    => trim((string)$val),
    ];
}

function read_menu(): ?SimpleXMLElement {
    $response = eta_fetch('/user/menu');
    if ($response === null) return null;
    return parse_xml($response);
}

function log_value(string $uri, string $name, string $strValue, string $unit, string $rawValue, string $source = 'web'): void {
    $timestamp = date('Y-m-d H:i:s');
    $line = "$timestamp\t$name\t$strValue\t$unit\t$rawValue\t$uri\t$source\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

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

function calc_consumption(string $heroUri): array {
    $empty = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'stockDaily'=>[]];
    if (!file_exists(LOG_FILE)) return $empty;

    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $readings = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        $uri = trim($parts[5] ?? '');
        if ($uri !== $heroUri) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false) continue;
        // strValue (parts[2]) verwenden, nicht rawValue (parts[4])
        $val = floatval(str_replace(',', '.', $parts[2]));
        $readings[] = ['ts' => $ts, 'value' => $val];
    }

    if (count($readings) < 2) return $empty;

    // Bestandsverlauf: letzter Wert pro Tag
    $stockDaily = [];
    foreach ($readings as $r) {
        $day = date('Y-m-d', $r['ts']);
        $stockDaily[$day] = $r['value'];
    }

    $daily = $weekly = $monthly = $yearly = [];

    for ($i = 1; $i < count($readings); $i++) {
        $prev = $readings[$i - 1];
        $curr = $readings[$i];
        $diff = $prev['value'] - $curr['value'];
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

    foreach ($daily   as &$v) $v = round($v);
    foreach ($weekly  as &$v) $v = round($v);
    foreach ($monthly as &$v) $v = round($v);
    foreach ($yearly  as &$v) $v = round($v);

    $daily   = array_slice($daily,   -30, null, true);
    $weekly  = array_slice($weekly,  -12, null, true);
    $monthly = array_slice($monthly, -12, null, true);
    $yearly     = array_slice($yearly,     -5,  null, true);
    $stockDaily = array_slice($stockDaily, -60, null, true);

    return compact('daily', 'weekly', 'monthly', 'yearly', 'stockDaily');
}

/**
 * Baut Zeitreihen-Daten für den Solar-Tab.
 * Gibt alle Messpunkte der letzten $hours Stunden zurück,
 * aufgeteilt in Labels (Zeitstempel) und je eine Datenserie pro Variable.
 */
function calc_solar_timeseries(array $solarConfig, int $hours = 168): array {
    $empty = ['labels' => [], 'series' => [], 'current' => []];
    if (!file_exists(LOG_FILE) || empty($solarConfig)) return $empty;

    $uriMap = [];
    foreach ($solarConfig as $s) {
        $uriMap[$s['uri']] = $s['name'];
    }
    $targetUris = array_keys($uriMap);
    $cutoff = time() - ($hours * 3600);

    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $byTs  = [];

    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        $uri = trim($parts[5]);
        if (!in_array($uri, $targetUris)) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false || $ts < $cutoff) continue;
        $tsKey = $parts[0];
        $value = floatval(str_replace(',', '.', $parts[2]));
        $byTs[$tsKey][$uri] = $value;
    }

    ksort($byTs);
    if (empty($byTs)) return $empty;

    $labels = array_map(fn($k) => date('d.m H:i', strtotime($k)), array_keys($byTs));

    $colorMap = [
        '/120/10221/0/0/12275' => '#ff6b35',
        '/120/10221/0/0/12197' => '#95d5b2',
        '/120/10251/0/0/12242' => '#64b5f6',
        '/120/10251/0/0/12244' => '#4488cc',
    ];

    $series  = [];
    $current = [];
    foreach ($targetUris as $uri) {
        $data = [];
        foreach ($byTs as $uriValues) {
            $data[] = isset($uriValues[$uri]) ? $uriValues[$uri] : null;
        }
        $lastVal = null;
        foreach (array_reverse($data) as $v) {
            if ($v !== null) { $lastVal = $v; break; }
        }
        $series[] = [
            'uri'   => $uri,
            'name'  => $uriMap[$uri],
            'data'  => $data,
            'color' => $colorMap[$uri] ?? '#e94560',
        ];
        $current[$uri] = $lastVal;
    }

    return ['labels' => $labels, 'series' => $series, 'current' => $current];
}

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

if ($action === 'addtile' && isset($_GET['uri'], $_GET['name'])) {
    $newUri  = $_GET['uri'];
    $newName = $_GET['name'];
    $exists  = ($CONFIG['hero']['uri'] === $newUri);
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

if ($action === 'sethero' && isset($_GET['uri'], $_GET['name'])) {
    $newUri  = $_GET['uri'];
    $newName = $_GET['name'];
    $oldHero = $CONFIG['hero'];
    $alreadyTile = false;
    foreach ($CONFIG['tiles'] as $t) {
        if ($t['uri'] === $oldHero['uri']) { $alreadyTile = true; break; }
    }
    if (!$alreadyTile) {
        $CONFIG['tiles'][] = $oldHero;
    }
    $CONFIG['tiles'] = array_values(array_filter($CONFIG['tiles'], function($t) use ($newUri) {
        return $t['uri'] !== $newUri;
    }));
    $CONFIG['hero'] = ['uri' => $newUri, 'name' => $newName];
    save_config($CONFIG);
    $message = "Hero gesetzt: $newName";
    $action = 'dashboard';
}

if ($action === 'reset') {
    $CONFIG = json_decode(DEFAULT_CONFIG, true);
    save_config($CONFIG);
    $message = "Dashboard auf Standard zurueckgesetzt.";
    $action = 'settings';
}

if ($action === 'savesettings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $etaIp   = trim($_POST['eta_ip'] ?? '');
    $etaPort = trim($_POST['eta_port'] ?? '');
    if ($etaIp)   $CONFIG['eta_ip']   = $etaIp;
    if ($etaPort) $CONFIG['eta_port'] = intval($etaPort);

    $heroUri  = trim($_POST['hero_uri'] ?? '');
    $heroName = trim($_POST['hero_name'] ?? '');
    if ($heroUri && $heroName) {
        $CONFIG['hero'] = ['uri' => $heroUri, 'name' => $heroName];
    }
    $tileUris  = $_POST['tile_uri'] ?? [];
    $tileNames = $_POST['tile_name'] ?? [];
    $newTiles  = [];
    for ($i = 0; $i < count($tileUris); $i++) {
        $u = trim($tileUris[$i] ?? '');
        $n = trim($tileNames[$i] ?? '');
        if ($u && $n) $newTiles[] = ['uri' => $u, 'name' => $n];
    }
    $CONFIG['tiles'] = $newTiles;

    $solarUris  = $_POST['solar_uri'] ?? [];
    $solarNames = $_POST['solar_name'] ?? [];
    $newSolar   = [];
    for ($i = 0; $i < count($solarUris); $i++) {
        $u = trim($solarUris[$i] ?? '');
        $n = trim($solarNames[$i] ?? '');
        if ($u && $n) $newSolar[] = ['uri' => $u, 'name' => $n];
    }
    $CONFIG['solar'] = $newSolar;

    save_config($CONFIG);
    $message = "Einstellungen gespeichert.";
    $action   = 'settings';
}

if ($action === 'fetchall') {
    $count      = 0;
    $loggedUris = [];

    $data = read_variable($CONFIG['hero']['uri']);
    if ($data) {
        log_value($CONFIG['hero']['uri'], $CONFIG['hero']['name'], $data['strValue'], $data['unit'], $data['rawValue']);
        $loggedUris[] = $CONFIG['hero']['uri'];
        $count++;
    }
    foreach ($CONFIG['tiles'] as $tile) {
        if (in_array($tile['uri'], $loggedUris)) continue;
        $data = read_variable($tile['uri']);
        if ($data) {
            log_value($tile['uri'], $tile['name'], $data['strValue'], $data['unit'], $data['rawValue']);
            $loggedUris[] = $tile['uri'];
            $count++;
        }
    }
    foreach ($CONFIG['solar'] ?? [] as $s) {
        if (in_array($s['uri'], $loggedUris)) continue;
        $data = read_variable($s['uri']);
        if ($data) {
            log_value($s['uri'], $s['name'], $data['strValue'], $data['unit'], $data['rawValue']);
            $loggedUris[] = $s['uri'];
            $count++;
        }
    }
    $message = "$count Variablen erfolgreich abgerufen und geloggt.";
    $action  = 'dashboard';
}

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

$heroData      = null;
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

$consumption = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'stock'=>[]];
if ($action === 'verbrauch') {
    $consumption = calc_consumption($CONFIG['hero']['uri']);
}

$solarTimeseries = ['labels' => [], 'series' => [], 'current' => []];
if ($action === 'solar') {
    $solarTimeseries = calc_solar_timeseries($CONFIG['solar'] ?? []);
}

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
    <?php if($action==='verbrauch'||$action==='solar'):?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <?php endif?>
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
        /* Solar */
        .solar-summary{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
        .solar-card{border-radius:8px;padding:12px;text-align:center;position:relative}
        .solar-card .s-label{font-size:.7em;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .solar-card .s-val{font-size:2em;font-weight:bold;line-height:1.1}
        .solar-card .s-unit{font-size:.4em;margin-left:3px;color:#888}
        .solar-kollektor{background:linear-gradient(135deg,#3d1a00,#1a0a00);border:1px solid #ff6b35}
        .solar-kollektor .s-label{color:#ff6b35}
        .solar-kollektor .s-val{color:#ff6b35}
        .solar-aussen{background:linear-gradient(135deg,#1b3a2e,#0f2419);border:1px solid #95d5b2}
        .solar-aussen .s-label{color:#95d5b2}
        .solar-aussen .s-val{color:#95d5b2}
        .solar-puffer-oben{background:linear-gradient(135deg,#0d2a4a,#071825);border:1px solid #64b5f6}
        .solar-puffer-oben .s-label{color:#64b5f6}
        .solar-puffer-oben .s-val{color:#64b5f6}
        .solar-puffer-unten{background:linear-gradient(135deg,#0a1e3a,#050f1e);border:1px solid #4488cc}
        .solar-puffer-unten .s-label{color:#4488cc}
        .solar-puffer-unten .s-val{color:#4488cc}
        .solar-chart-wrap{position:relative;height:280px}
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
            .solar-chart-wrap{height:320px}
            .consumption-summary{gap:10px}
            .cons-item .cons-val{font-size:1.6em}
            th,td{padding:8px 12px;font-size:.9em}
            .tile-row{grid-template-columns:1fr 2.5fr 30px}
            .solar-summary{grid-template-columns:repeat(4,1fr)}
            .solar-card .s-val{font-size:2.2em}
        }
        /* Desktop */
        @media(min-width:900px){
            .hero-card .hero-value{font-size:6em}
            .var-grid{grid-template-columns:repeat(4,1fr)}
            .chart-wrap{height:350px}
            .solar-chart-wrap{height:360px}
        }
    </style>
</head>
<body>
<div class="container">
    <h1>ETA Pellet Tracker</h1>
    <p class="subtitle">Kessel: <?=htmlspecialchars($CONFIG['eta_ip'])?>:<?=intval($CONFIG['eta_port'])?></p>

    <nav>
        <a href="?action=dashboard" class="<?=$action==='dashboard'?'active':''?>">Dashboard</a>
        <a href="?action=verbrauch" class="<?=$action==='verbrauch'?'active':''?>">Verbrauch</a>
        <a href="?action=solar"     class="<?=$action==='solar'?'active':''?>">Solar</a>
        <a href="?action=log"       class="<?=$action==='log'?'active':''?>">Log</a>
        <a href="?action=menu"      class="<?=$action==='menu'?'active':''?>">Menubaum</a>
        <a href="?action=settings"  class="<?=$action==='settings'?'active':''?>">Einstellungen</a>
    </nav>

    <?php if($message):?><div class="msg success"><?=htmlspecialchars($message)?></div><?php endif?>
    <?php if($error):?><div class="msg error"><?=htmlspecialchars($error)?></div><?php endif?>

    <?php if($action==='dashboard'):?>

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

        <div class="card">
            <h2>Pelletverbrauch</h2>
            <?php
                $hasStock = !empty($consumption['stockDaily']);
                $hasConsumption = !empty($consumption['daily']) || !empty($consumption['weekly'])
                        || !empty($consumption['monthly']) || !empty($consumption['yearly']);
            ?>
            <?php if($hasStock || $hasConsumption):?>
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
                    <button class="active" onclick="showChart('stock',this)">Bestandsverlauf</button>
                    <button onclick="showChart('daily',this)">Taeglich</button>
                    <button onclick="showChart('weekly',this)">Woechentlich</button>
                    <button onclick="showChart('monthly',this)">Monatlich</button>
                    <button onclick="showChart('yearly',this)">Jaehrlich</button>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-stock" class="active"></canvas>
                    <canvas id="chart-daily"></canvas>
                    <canvas id="chart-weekly"></canvas>
                    <canvas id="chart-monthly"></canvas>
                    <canvas id="chart-yearly"></canvas>
                </div>

                <script>
                const chartData = {
                    stock:   {labels:<?=json_encode(array_keys($consumption['stockDaily']))?>,   data:<?=json_encode(array_values($consumption['stockDaily']))?>},
                    daily:   {labels:<?=json_encode(array_keys($consumption['daily']))?>,   data:<?=json_encode(array_values($consumption['daily']))?>},
                    weekly:  {labels:<?=json_encode(array_keys($consumption['weekly']))?>,  data:<?=json_encode(array_values($consumption['weekly']))?>},
                    monthly: {labels:<?=json_encode(array_keys($consumption['monthly']))?>, data:<?=json_encode(array_values($consumption['monthly']))?>},
                    yearly:  {labels:<?=json_encode(array_keys($consumption['yearly']))?>,  data:<?=json_encode(array_values($consumption['yearly']))?>}
                };
                const charts = {};
                function makeChart(id, labels, data) {
                    const ctx = document.getElementById('chart-'+id).getContext('2d');
                    const isStock = (id === 'stock');
                    charts[id] = new Chart(ctx, {
                        type: isStock ? 'line' : 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: isStock ? 'Lager Vorrat (kg)' : 'Verbrauch (kg)',
                                data: data,
                                backgroundColor: isStock ? 'rgba(149,213,178,0.15)' : 'rgba(233,69,96,0.7)',
                                borderColor: isStock ? '#95d5b2' : '#e94560',
                                borderWidth: isStock ? 2 : 1,
                                borderRadius: isStock ? 0 : 4,
                                fill: isStock,
                                tension: 0.3,
                                pointBackgroundColor: isStock ? '#95d5b2' : undefined,
                                pointRadius: isStock ? 4 : undefined
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {display: false},
                                tooltip: {callbacks: {label: ctx => ctx.parsed.y + ' kg'}}
                            },
                            scales: {
                                x: {ticks:{color:'#888',maxRotation:45},grid:{color:'rgba(255,255,255,0.05)'}},
                                y: {beginAtZero:!isStock,ticks:{color:'#888',callback:function(v){return v+' kg'}},grid:{color:'rgba(255,255,255,0.08)'}}
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
                if (chartData.stock.labels.length > 0) {
                    makeChart('stock', chartData.stock.labels, chartData.stock.data);
                }
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch keine Verbrauchsdaten vorhanden.<br>
                    <small>Der Cronjob muss mindestens 2x gelaufen sein, damit ein Verbrauch berechnet werden kann.</small>
                </div>
            <?php endif?>
        </div>

    <?php elseif($action==='solar'):?>

        <div class="card">
            <h2>Solaranlage</h2>

            <?php
                $sc = $solarTimeseries['current'];
                $solarVarMap = [];
                foreach ($CONFIG['solar'] ?? [] as $s) $solarVarMap[$s['uri']] = $s['name'];
                $kollUri  = '/120/10221/0/0/12275';
                $aussenUri= '/120/10221/0/0/12197';
                $pufOUri  = '/120/10251/0/0/12242';
                $pufUUri  = '/120/10251/0/0/12244';
                $fmtVal = fn($v) => $v !== null ? number_format($v, 1, ',', '.') : '--';
            ?>

            <div class="solar-summary">
                <div class="solar-card solar-kollektor">
                    <div class="s-label">Kollektor</div>
                    <div class="s-val"><?=$fmtVal($sc[$kollUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="solar-card solar-aussen">
                    <div class="s-label">Außentemperatur</div>
                    <div class="s-val"><?=$fmtVal($sc[$aussenUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="solar-card solar-puffer-oben">
                    <div class="s-label">Puffer oben</div>
                    <div class="s-val"><?=$fmtVal($sc[$pufOUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="solar-card solar-puffer-unten">
                    <div class="s-label">Puffer unten</div>
                    <div class="s-val"><?=$fmtVal($sc[$pufUUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
            </div>

            <?php if(!empty($solarTimeseries['labels'])):?>
                <div class="chart-tabs">
                    <button class="active" onclick="setSolarRange(24,this)">24 Stunden</button>
                    <button onclick="setSolarRange(48,this)">48 Stunden</button>
                    <button onclick="setSolarRange(168,this)">7 Tage</button>
                </div>
                <div class="solar-chart-wrap">
                    <canvas id="solar-chart"></canvas>
                </div>

                <script>
                const solarAllLabels = <?=json_encode($solarTimeseries['labels'])?>;
                const solarSeries    = <?=json_encode(array_map(fn($s)=>['name'=>$s['name'],'data'=>$s['data'],'color'=>$s['color']], $solarTimeseries['series']))?>;
                let solarChart = null;

                function setSolarRange(range, btn) {
                    document.querySelectorAll('.chart-tabs button').forEach(b=>b.classList.remove('active'));
                    btn.classList.add('active');
                    buildSolarChart(range);
                }

                function buildSolarChart(range) {
                    const n      = solarAllLabels.length;
                    const start  = Math.max(0, n - range);
                    const labels = solarAllLabels.slice(start);
                    const datasets = solarSeries.map(s => ({
                        label:           s.name,
                        data:            s.data.slice(start),
                        borderColor:     s.color,
                        backgroundColor: s.color + '18',
                        borderWidth:     2,
                        pointRadius:     range <= 48 ? 3 : 1,
                        pointHoverRadius:5,
                        fill:            false,
                        tension:         0.35,
                        spanGaps:        true
                    }));

                    if (solarChart) solarChart.destroy();
                    const ctx = document.getElementById('solar-chart').getContext('2d');
                    solarChart = new Chart(ctx, {
                        type: 'line',
                        data: { labels, datasets },
                        options: {
                            responsive:          true,
                            maintainAspectRatio: false,
                            interaction: { mode:'index', intersect:false },
                            plugins: {
                                legend: {
                                    display: true,
                                    labels:  { color:'#eee', boxWidth:12, font:{size:11} }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: ctx => {
                                            const v = ctx.parsed.y;
                                            return ctx.dataset.label + ': ' + (v !== null ? v.toFixed(1) + ' °C' : '--');
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    ticks: { color:'#888', maxRotation:45, maxTicksLimit:24 },
                                    grid:  { color:'rgba(255,255,255,0.05)' }
                                },
                                y: {
                                    ticks: { color:'#888', callback: v => v + ' °C' },
                                    grid:  { color:'rgba(255,255,255,0.08)' }
                                }
                            }
                        }
                    });
                }

                buildSolarChart(24);
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch keine Solardaten im Log vorhanden.<br>
                    <small>Sobald der stündliche Cronjob gelaufen ist, erscheinen hier die Temperaturkurven.</small>
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
                <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:4px">ETA Kessel</h3>
                <div class="tile-row" style="grid-template-columns:2fr 1fr">
                    <div>
                        <label>IP-Adresse</label>
                        <input type="text" name="eta_ip" value="<?=htmlspecialchars($CONFIG['eta_ip'])?>" placeholder="192.168.88.36">
                    </div>
                    <div>
                        <label>Port</label>
                        <input type="text" name="eta_port" value="<?=intval($CONFIG['eta_port'])?>" placeholder="8080">
                    </div>
                </div>

                <div class="settings-section">
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
                </div>

                <div class="settings-section">
                    <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:8px">Dashboard-Kacheln</h3>
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

                <div class="settings-section">
                    <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:8px">Solar-Variablen</h3>
                    <p style="color:#888;font-size:.8em;margin-bottom:10px">Diese Variablen werden stündlich geloggt und im Solar-Tab als Kurve dargestellt.</p>
                    <div id="solar-list">
                    <?php foreach($CONFIG['solar'] ?? [] as $i => $s):?>
                        <div class="tile-row">
                            <div>
                                <?php if($i===0):?><label>Name</label><?php endif?>
                                <input type="text" name="solar_name[]" value="<?=htmlspecialchars($s['name'])?>">
                            </div>
                            <div>
                                <?php if($i===0):?><label>URI-Pfad</label><?php endif?>
                                <input type="text" name="solar_uri[]" value="<?=htmlspecialchars($s['uri'])?>">
                            </div>
                            <a href="#" class="btn-del-inline" onclick="this.parentElement.remove();return false">&times;</a>
                        </div>
                    <?php endforeach?>
                    </div>
                    <a href="#" class="btn btn-sm btn-outline" onclick="addSolarRow();return false">+ Solar-Variable hinzufuegen</a>
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
        function addSolarRow() {
            const list = document.getElementById('solar-list');
            const row = document.createElement('div');
            row.className = 'tile-row';
            row.innerHTML = '<div><input type="text" name="solar_name[]" placeholder="Name"></div>'
                + '<div><input type="text" name="solar_uri[]" placeholder="/node/fub/fkt/io/var"></div>'
                + '<a href="#" class="btn-del-inline" onclick="this.parentElement.remove();return false">&times;</a>';
            list.appendChild(row);
        }
        </script>

    <?php endif?>
</div>
</body>
</html>
