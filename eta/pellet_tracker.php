<?php
/**
 * ETA Pellet Verbrauch Tracker
 * Liest den Pelletverbrauch via ETA RESTful API und loggt in eine TXT-Datei.
 */

// ── Konfiguration ──────────────────────────────────────────────
define('LOG_FILE', __DIR__ . '/pellet_verbrauch.txt');
define('CONFIG_FILE', __DIR__ . '/config.json');
define('EVENTS_FILE', __DIR__ . '/pellet_events.txt');

define('DEFAULT_CONFIG', json_encode([
    'eta_ip'   => '192.168.88.36',
    'eta_port' => 8080,
    'hero' => [
        'uri'  => '/40/10201/0/0/12015',
        'name' => 'Lager Vorrat',
    ],
    // Zaehler der tatsaechlich verbrannten kg -- Basis fuer Verbrauch und Bilanz.
    'counter' => [
        'uri'  => '/40/10021/0/0/12016',
        'name' => 'Gesamtverbrauch',
    ],
    // Vorratsbehaelter im Kessel (fasst rund 30 kg), wird aus dem Lager nachgesaugt.
    'hopper' => [
        'uri'  => '/40/10021/0/0/12011',
        'name' => 'Inhalt Pelletsbehälter',
    ],
    // Gebindegroesse fuer den Sack-Button auf dem Dashboard.
    'sack_kg' => 15,
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
    // Solarstatistik. Der Kessel hat keinen Ertragszaehler, gezaehlt wird die Zeit
    // oberhalb der Kollektor-Starttemperatur. Pumpe und Speicherfuehler werden
    // mitgeloggt, damit spaeter auf echte Pumpenlaufzeit umgestellt werden kann.
    'solar_stats' => [
        'collector_uri' => '/120/10221/0/0/12275',
        'threshold'     => 40,
        'pump'          => ['uri' => '/120/10221/0/0/12278', 'name' => 'Kollektorpumpe'],
        'store'         => ['uri' => '/120/10221/0/0/12781', 'name' => 'Speicher 1 unten'],
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
            if (!isset($config['counter']))  $config['counter']  = $defaults['counter'];
            if (!isset($config['hopper']))   $config['hopper']   = $defaults['hopper'];
            if (!isset($config['sack_kg']))  $config['sack_kg']  = $defaults['sack_kg'];
            $config['solar_stats'] = array_merge($defaults['solar_stats'], $config['solar_stats'] ?? []);
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

/**
 * Bestands-Ereignisse (Befuellungen und Saecke).
 *
 * Der Kessel misst das Lager nicht, er bucht nur: eingetragene Fuellmenge minus
 * verbrannte kg. Saecke, die bei einem Klemmer von Hand in den Behaelter gekippt
 * werden, laufen an dieser Buchhaltung vorbei -- der Lagerwert wird dadurch mit
 * der Zeit zu niedrig und kann negativ werden. Deshalb fuehrt das Dashboard die
 * Bilanz selbst, auf Basis des Zaehlers der tatsaechlich verbrannten kg.
 *
 * Format (Tab-separiert): Zeitstempel, Typ, kg, Zaehlerstand, Behaelterinhalt, Notiz
 * Typen: 'bestand' = Lager enthaelt jetzt X kg, 'sack' = X kg direkt nachgefuellt.
 */
function read_events(): array {
    if (!file_exists(EVENTS_FILE)) return [];
    $lines  = file(EVENTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $events = [];
    foreach ($lines as $line) {
        $p = explode("\t", $line);
        if (count($p) < 5) continue;
        $ts = strtotime($p[0]);
        if ($ts === false) continue;
        $events[] = [
            'ts'        => $ts,
            'timestamp' => $p[0],
            'type'      => trim($p[1]),
            'kg'        => floatval(str_replace(',', '.', $p[2])),
            'counter'   => floatval(str_replace(',', '.', $p[3])),
            'hopper'    => floatval(str_replace(',', '.', $p[4])),
            'note'      => trim($p[5] ?? ''),
        ];
    }
    usort($events, function($a, $b) { return $a['ts'] <=> $b['ts']; });
    return $events;
}

function append_event(string $type, float $kg, float $counter, float $hopper, string $note = ''): bool {
    $line = date('Y-m-d H:i:s') . "\t" . $type . "\t" . $kg . "\t" . $counter . "\t" . $hopper
          . "\t" . str_replace(["\t", "\n"], ' ', $note) . "\n";
    return file_put_contents(EVENTS_FILE, $line, FILE_APPEND | LOCK_EX) !== false;
}

function delete_event(int $idx): ?array {
    $events = read_events();
    if (!isset($events[$idx])) return null;
    $removed = $events[$idx];
    unset($events[$idx]);
    $out = '';
    foreach ($events as $e) {
        $out .= $e['timestamp'] . "\t" . $e['type'] . "\t" . $e['kg'] . "\t" . $e['counter']
              . "\t" . $e['hopper'] . "\t" . $e['note'] . "\n";
    }
    return file_put_contents(EVENTS_FILE, $out, LOCK_EX) !== false ? $removed : null;
}

/** Letzter geloggter Wert einer Variablen -- Rueckfallebene, wenn der Kessel nicht antwortet. */
function last_logged_value(string $uri): ?float {
    if (!file_exists(LOG_FILE)) return null;
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $p = explode("\t", $lines[$i]);
        if (count($p) < 6 || trim($p[5]) !== $uri) continue;
        return floatval(str_replace(',', '.', $p[2]));
    }
    return null;
}

/** Aktueller Wert: erst den Kessel fragen, sonst den letzten Logeintrag nehmen. */
function current_value(string $uri): ?float {
    $data = read_variable($uri);
    if ($data !== null && $data['strValue'] !== '') {
        return floatval(str_replace(',', '.', $data['strValue']));
    }
    return last_logged_value($uri);
}

/**
 * Bilanz des tatsaechlich vorhandenen Vorrats:
 *
 *   Gesamt = (Lager + Behaelter beim letzten Bestands-Eintrag)
 *            + seither nachgefuellte Saecke
 *            - seither verbrannte kg (Zaehler)
 *   Lager  = Gesamt - aktueller Behaelterinhalt
 *
 * Der Zaehler ist die einzige Groesse, die den echten Verbrauch misst.
 */
function calc_stock(?float $counterNow, ?float $hopperNow): array {
    $stock = [
        'ok'     => false,
        'reason' => '',
        'total'  => null,
        'lager'  => null,
        'hopper' => $hopperNow,
        'base'   => null,
        'sacks'  => 0.0,
        'burned' => null,
    ];

    $events = read_events();
    $base   = null;
    foreach ($events as $e) {
        if ($e['type'] === 'bestand') $base = $e;
    }
    if ($base === null) {
        $stock['reason'] = 'Noch kein Bestand eingetragen — unten die Fuellmenge des Lagers eintragen.';
        return $stock;
    }
    if ($counterNow === null) {
        $stock['reason'] = 'Zaehlerstand (' . $GLOBALS['CONFIG']['counter']['name'] . ') nicht lesbar.';
        return $stock;
    }

    $sacks = 0.0;
    foreach ($events as $e) {
        if ($e['type'] === 'sack' && $e['ts'] >= $base['ts']) $sacks += $e['kg'];
    }

    $burned = $counterNow - $base['counter'];
    $total  = $base['kg'] + $base['hopper'] + $sacks - $burned;

    $stock['ok']     = true;
    $stock['base']   = $base;
    $stock['sacks']  = $sacks;
    $stock['burned'] = $burned;
    $stock['total']  = $total;
    $stock['lager']  = ($hopperNow !== null) ? $total - $hopperNow : null;
    return $stock;
}

/**
 * Verbrauch je Tag/Woche/Monat/Jahr aus dem Zaehler der verbrannten kg.
 *
 * Frueher wurde dafuer der Rueckgang des Lagerwerts benutzt. Das ging schief:
 * der Kessel verbrennt nicht aus dem Lager, sondern aus dem Vorratsbehaelter,
 * der schubweise nachgesaugt wird -- gemessen wurde also der Saugzeitpunkt,
 * nicht das Verbrennen. An Liefertagen fiel der Tagesverbrauch komplett aus,
 * und sobald die Lagerbuchhaltung auf 0 lief, wurden die Werte unsinnig.
 */
function calc_consumption(string $counterUri): array {
    $empty = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[]];
    if (!file_exists(LOG_FILE)) return $empty;

    $lines    = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $readings = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        if (trim($parts[5] ?? '') !== $counterUri) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false) continue;
        // strValue (parts[2]) verwenden, nicht rawValue (parts[4])
        $readings[] = ['ts' => $ts, 'value' => floatval(str_replace(',', '.', $parts[2]))];
    }

    if (count($readings) < 2) return $empty;
    usort($readings, function($a, $b) { return $a['ts'] <=> $b['ts']; });

    $daily = $weekly = $monthly = $yearly = [];

    foreach ($readings as $i => $r) {
        if ($i === 0) continue;

        // Der Zaehler laeuft nur vorwaerts. Ein Rueckwaertssprung ist ein
        // Ausreisser oder ein Zaehlerwechsel -- beides ist kein Verbrauch.
        $diff = $r['value'] - $readings[$i - 1]['value'];
        if ($diff <= 0) continue;

        $day   = date('Y-m-d', $r['ts']);
        $week  = date('o-\KW', $r['ts']);
        $month = date('Y-m', $r['ts']);
        $year  = date('Y', $r['ts']);

        $daily[$day]     = ($daily[$day] ?? 0) + $diff;
        $weekly[$week]   = ($weekly[$week] ?? 0) + $diff;
        $monthly[$month] = ($monthly[$month] ?? 0) + $diff;
        $yearly[$year]   = ($yearly[$year] ?? 0) + $diff;
    }

    foreach ($daily   as &$v) $v = round($v);
    foreach ($weekly  as &$v) $v = round($v);
    foreach ($monthly as &$v) $v = round($v);
    foreach ($yearly  as &$v) $v = round($v);
    unset($v);

    $daily   = array_slice($daily,   -30, null, true);
    $weekly  = array_slice($weekly,  -12, null, true);
    $monthly = array_slice($monthly, -12, null, true);
    $yearly  = array_slice($yearly,  -5,  null, true);

    return compact('daily', 'weekly', 'monthly', 'yearly');
}

/**
 * Vorratsverlauf je Messpunkt: Gesamtvorrat, Lager und Behaelterinhalt.
 *
 * Gerechnet wird wie in calc_stock(), nur eben fuer jeden geloggten Zaehlerstand
 * statt nur fuer den aktuellen: Fuellmenge des letzten Bestands-Eintrags plus
 * seither nachgetragene Saecke minus das, was der Zaehler seitdem verbrannt hat.
 * Der Behaelterinhalt kommt aus dem Log (letzter Wert bis zum Zeitpunkt), das
 * Lager ist die Differenz.
 */
function stock_rows(int $sinceTs = 0): array {
    if (!file_exists(LOG_FILE)) return [];
    $events = read_events();
    if (!$events) return [];

    $counterUri = $GLOBALS['CONFIG']['counter']['uri'];
    $hopperUri  = $GLOBALS['CONFIG']['hopper']['uri'];

    $lines   = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $counter = [];
    $hopper  = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        $uri = trim($parts[5]);
        if ($uri !== $counterUri && $uri !== $hopperUri) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false) continue;
        $val = floatval(str_replace(',', '.', $parts[2]));
        if ($uri === $counterUri) $counter[$ts] = $val; else $hopper[$ts] = $val;
    }
    if (!$counter) return [];
    ksort($counter);
    ksort($hopper);

    $hopperTs  = array_keys($hopper);
    $hopperIdx = 0;
    $lastHop   = null;
    $rows      = [];

    foreach ($counter as $ts => $counterVal) {
        // Behaelterwert bis zu diesem Zeitpunkt nachziehen.
        while ($hopperIdx < count($hopperTs) && $hopperTs[$hopperIdx] <= $ts) {
            $lastHop = $hopper[$hopperTs[$hopperIdx]];
            $hopperIdx++;
        }
        if ($ts < $sinceTs) continue;

        $base = null;
        foreach ($events as $e) {
            if ($e['type'] === 'bestand' && $e['ts'] <= $ts) $base = $e;
        }
        if ($base === null) continue;

        $sacks = 0.0;
        foreach ($events as $e) {
            if ($e['type'] === 'sack' && $e['ts'] >= $base['ts'] && $e['ts'] <= $ts) $sacks += $e['kg'];
        }

        $total = $base['kg'] + $base['hopper'] + $sacks - ($counterVal - $base['counter']);
        $rows[] = [
            'ts'     => $ts,
            'total'  => round($total, 1),
            'hopper' => $lastHop,
            'lager'  => ($lastHop !== null) ? round($total - $lastHop, 1) : null,
        ];
    }
    return $rows;
}

/** Farben und Namen der drei Vorratskurven -- an einer Stelle, fuer Graph und Kacheln. */
function stock_series_meta(): array {
    return [
        ['key' => 'total',  'name' => 'Vorrat gesamt', 'color' => '#e94560'],
        ['key' => 'lager',  'name' => 'Lager',         'color' => '#95d5b2'],
        ['key' => 'hopper', 'name' => 'Behälter',      'color' => '#f0a202'],
    ];
}

/** Vorratsverlauf in Stundenaufloesung fuer die kurzen Zeitraeume im Graphen. */
function calc_stock_timeseries(int $hours = 168): array {
    $rows = stock_rows(time() - ($hours * 3600));
    if (!$rows) return ['labels' => [], 'series' => [], 'current' => []];

    $labels  = [];
    $columns = ['total' => [], 'lager' => [], 'hopper' => []];
    foreach ($rows as $r) {
        $labels[] = date('d.m H:i', $r['ts']);
        foreach ($columns as $key => $_) $columns[$key][] = $r[$key];
    }

    $series  = [];
    $current = [];
    foreach (stock_series_meta() as $m) {
        $series[] = ['name' => $m['name'], 'data' => $columns[$m['key']], 'color' => $m['color']];
        $last = null;
        foreach (array_reverse($columns[$m['key']]) as $v) {
            if ($v !== null) { $last = $v; break; }
        }
        $current[$m['key']] = $last;
    }
    return ['labels' => $labels, 'series' => $series, 'current' => $current];
}

/** Vorratsverlauf je Tag (letzter Wert des Tages) fuer Monat und Jahr. */
function calc_stock_daily(int $days = 365): array {
    $rows = stock_rows(strtotime('today') - (($days - 1) * 86400));
    if (!$rows) return ['labels' => [], 'series' => []];

    $byDay = [];
    foreach ($rows as $r) $byDay[date('Y-m-d', $r['ts'])] = $r;
    ksort($byDay);

    $labels  = [];
    $columns = ['total' => [], 'lager' => [], 'hopper' => []];
    foreach ($byDay as $day => $r) {
        $labels[] = date('d.m.', strtotime($day));
        foreach ($columns as $key => $_) $columns[$key][] = $r[$key];
    }

    $series = [];
    foreach (stock_series_meta() as $m) {
        $series[] = ['name' => $m['name'], 'data' => $columns[$m['key']], 'color' => $m['color']];
    }
    return ['labels' => $labels, 'series' => $series];
}

/**
 * Tageswerte der Solar-Variablen fuer die langen Zeitraeume im Graphen.
 *
 * Stuendliche Rohwerte ergeben ueber einen Monat rund 720 und ueber ein Jahr
 * ueber 8000 Punkte -- unlesbar und unnoetig gross. Fuer Monat und Jahr wird
 * deshalb je Tag das Maximum gezeigt: beim Kollektor ist das die Tagesspitze,
 * also genau das Signal, das ueber lange Zeitraeume interessiert. Das Mittel
 * waere dort vom Nachtwert erschlagen.
 */
function calc_solar_daily(array $solarConfig, int $days = 365): array {
    $empty = ['labels' => [], 'series' => []];
    if (!file_exists(LOG_FILE) || empty($solarConfig)) return $empty;

    $uriMap = [];
    foreach ($solarConfig as $s) $uriMap[$s['uri']] = $s['name'];
    $cutoff = strtotime('today') - (($days - 1) * 86400);

    $lines  = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $byDay  = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        $uri = trim($parts[5]);
        if (!isset($uriMap[$uri])) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false || $ts < $cutoff) continue;
        $day   = date('Y-m-d', $ts);
        $value = floatval(str_replace(',', '.', $parts[2]));
        $byDay[$day][$uri] = max($byDay[$day][$uri] ?? -273.0, $value);
    }

    ksort($byDay);
    if (empty($byDay)) return $empty;

    $colorMap = [
        '/120/10221/0/0/12275' => '#ff6b35',
        '/120/10221/0/0/12197' => '#95d5b2',
        '/120/10251/0/0/12242' => '#64b5f6',
        '/120/10251/0/0/12244' => '#4488cc',
    ];

    $labels = array_map(function($d) { return date('d.m.', strtotime($d)); }, array_keys($byDay));
    $series = [];
    foreach (array_keys($uriMap) as $uri) {
        $data = [];
        foreach ($byDay as $dayValues) {
            $data[] = isset($dayValues[$uri]) ? round($dayValues[$uri], 1) : null;
        }
        $series[] = [
            'uri'   => $uri,
            'name'  => $uriMap[$uri],
            'data'  => $data,
            'color' => $colorMap[$uri] ?? '#e94560',
        ];
    }
    return ['labels' => $labels, 'series' => $series];
}

/**
 * Solarstatistik: Sonnenstunden je Tag/Woche/Monat/Jahr.
 *
 * Der Kessel hat keinen Ertragszaehler -- der Solar-Funktionsblock kennt nur
 * Temperaturen, Zustand und Pumpenleistung (im Menuebaum geprueft). Gezaehlt wird
 * deshalb die Zeit, in der der Kollektor ueber seiner Starttemperatur lag: die
 * Zeit, in der die Anlage liefern konnte. Das ist nicht der Ertrag -- bei vollem
 * Puffer steht die Pumpe, die Stunde zaehlt trotzdem.
 *
 * Jeder Messpunkt zaehlt mit dem Abstand zum naechsten, gedeckelt auf zwei
 * Stunden, damit Luecken im Log (ausgefallener Cronjob) keine Stunden erfinden.
 */
function calc_solar_stats(string $collectorUri, float $threshold): array {
    $empty = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'peakDaily'=>[]];
    if (!file_exists(LOG_FILE)) return $empty;

    $lines    = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $readings = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 6) continue;
        if (trim($parts[5] ?? '') !== $collectorUri) continue;
        $ts = strtotime($parts[0]);
        if ($ts === false) continue;
        $readings[] = ['ts' => $ts, 'value' => floatval(str_replace(',', '.', $parts[2]))];
    }
    if (!$readings) return $empty;
    usort($readings, function($a, $b) { return $a['ts'] <=> $b['ts']; });

    $daily = $weekly = $monthly = $yearly = $peakDaily = [];
    $maxGap = 7200; // zwei Stunden

    foreach ($readings as $i => $r) {
        $day = date('Y-m-d', $r['ts']);
        $peakDaily[$day] = max($peakDaily[$day] ?? -273.0, $r['value']);

        if ($r['value'] < $threshold) continue;
        $next = $readings[$i + 1]['ts'] ?? null;
        if ($next === null) continue;
        $hours = min($next - $r['ts'], $maxGap) / 3600;
        if ($hours <= 0) continue;

        $week  = date('o-\KW', $r['ts']);
        $month = date('Y-m', $r['ts']);
        $year  = date('Y', $r['ts']);

        $daily[$day]     = ($daily[$day] ?? 0) + $hours;
        $weekly[$week]   = ($weekly[$week] ?? 0) + $hours;
        $monthly[$month] = ($monthly[$month] ?? 0) + $hours;
        $yearly[$year]   = ($yearly[$year] ?? 0) + $hours;
    }

    foreach ($daily     as &$v) $v = round($v, 1);
    foreach ($weekly    as &$v) $v = round($v, 1);
    foreach ($monthly   as &$v) $v = round($v, 1);
    foreach ($yearly    as &$v) $v = round($v, 1);
    foreach ($peakDaily as &$v) $v = round($v, 1);
    unset($v);

    $daily     = array_slice($daily,     -30, null, true);
    $weekly    = array_slice($weekly,    -12, null, true);
    $monthly   = array_slice($monthly,   -12, null, true);
    $yearly    = array_slice($yearly,     -5, null, true);
    $peakDaily = array_slice($peakDaily, -30, null, true);

    return compact('daily', 'weekly', 'monthly', 'yearly', 'peakDaily');
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
$message = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
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
    foreach (['counter', 'hopper'] as $key) {
        $uri  = trim($_POST[$key . '_uri'] ?? '');
        $name = trim($_POST[$key . '_name'] ?? '');
        if ($uri && $name) $CONFIG[$key] = ['uri' => $uri, 'name' => $name];
    }
    $sackKg = floatval(str_replace(',', '.', trim($_POST['sack_kg'] ?? '')));
    if ($sackKg > 0) $CONFIG['sack_kg'] = $sackKg;

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

if ($action === 'addevent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = ($_POST['type'] ?? '') === 'bestand' ? 'bestand' : 'sack';
    $kg   = floatval(str_replace(',', '.', trim($_POST['kg'] ?? '')));

    if ($kg <= 0) {
        $error = 'Bitte eine Menge groesser 0 eintragen.';
    } else {
        // Zaehlerstand und Behaelterinhalt zum Zeitpunkt des Eintrags festhalten --
        // ohne sie laesst sich die Bilanz spaeter nicht zurueckrechnen.
        $counter = current_value($CONFIG['counter']['uri']);
        $hopper  = current_value($CONFIG['hopper']['uri']);
        if ($counter === null) {
            $error = 'Zaehlerstand nicht lesbar — Eintrag nicht gespeichert.';
        } elseif (!append_event($type, $kg, $counter, $hopper ?? 0.0, trim($_POST['note'] ?? ''))) {
            $error = 'Eintrag konnte nicht gespeichert werden (Schreibrechte?).';
        } else {
            $msg = ($type === 'bestand')
                ? 'Bestand eingetragen: Lager enthaelt jetzt ' . round($kg) . ' kg.'
                : round($kg) . ' kg Sack nachgetragen.';
            // Redirect, damit ein Reload den Eintrag nicht verdoppelt.
            header('Location: ?action=dashboard&msg=' . rawurlencode($msg));
            exit;
        }
    }
    $action = 'dashboard';
}

if ($action === 'delevent' && isset($_GET['idx'])) {
    $removed = delete_event((int)$_GET['idx']);
    if ($removed === null) {
        $error = 'Eintrag nicht gefunden.';
    } else {
        $message = 'Eintrag entfernt: ' . $removed['timestamp'] . ' (' . round($removed['kg']) . ' kg)';
    }
    $action = 'dashboard';
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
    // Zaehler und Behaelter sind die Basis der Bilanz -- immer mitloggen, auch
    // wenn sie nicht als Kachel konfiguriert sind.
    foreach ([$CONFIG['counter'], $CONFIG['hopper'],
              $CONFIG['solar_stats']['pump'], $CONFIG['solar_stats']['store']] as $must) {
        if (in_array($must['uri'], $loggedUris)) continue;
        $data = read_variable($must['uri']);
        if ($data) {
            log_value($must['uri'], $must['name'], $data['strValue'], $data['unit'], $data['rawValue']);
            $loggedUris[] = $must['uri'];
            $count++;
        }
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
$stock         = ['ok' => false, 'reason' => '', 'total' => null, 'lager' => null,
                  'hopper' => null, 'base' => null, 'sacks' => 0.0, 'burned' => null];
$events        = [];
$kesselLager   = null;
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

    // Bereits abgerufene Werte wiederverwenden, statt den Kessel doppelt zu fragen.
    $liveValues = [];
    if ($heroData) {
        $liveValues[$CONFIG['hero']['uri']] = floatval(str_replace(',', '.', $heroData['strValue']));
    }
    foreach ($dashboardData as $item) {
        if ($item['data']) {
            $liveValues[$item['uri']] = floatval(str_replace(',', '.', $item['data']['strValue']));
        }
    }
    $counterNow = $liveValues[$CONFIG['counter']['uri']] ?? current_value($CONFIG['counter']['uri']);
    $hopperNow  = $liveValues[$CONFIG['hopper']['uri']]  ?? current_value($CONFIG['hopper']['uri']);

    $stock       = calc_stock($counterNow, $hopperNow);
    $events      = read_events();
    $kesselLager = $liveValues[$CONFIG['hero']['uri']] ?? null;
}

$consumption     = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[]];
$stockSeries     = ['labels' => [], 'series' => [], 'current' => []];
$stockDailySerie = ['labels' => [], 'series' => []];
$counterNowVerbr = null;
if ($action === 'verbrauch') {
    $consumption     = calc_consumption($CONFIG['counter']['uri']);
    $stockSeries     = calc_stock_timeseries(168);
    $stockDailySerie = calc_stock_daily(365);
    $counterNowVerbr = current_value($CONFIG['counter']['uri']);
}

$solarTimeseries = ['labels' => [], 'series' => [], 'current' => []];
$solarDaily      = ['labels' => [], 'series' => []];
$solarStats      = ['daily'=>[],'weekly'=>[],'monthly'=>[],'yearly'=>[],'peakDaily'=>[]];
if ($action === 'solar') {
    $solarTimeseries = calc_solar_timeseries($CONFIG['solar'] ?? []);
    $solarDaily      = calc_solar_daily($CONFIG['solar'] ?? [], 365);
    $solarStats      = calc_solar_stats(
        $CONFIG['solar_stats']['collector_uri'],
        (float)$CONFIG['solar_stats']['threshold']
    );
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
        .hero-card .hero-sub{color:#95d5b2;font-size:1em;margin-top:10px}
        .hero-card .hero-note{color:#8a97a5;font-size:.75em;margin-top:8px;line-height:1.6}
        .hero-card .hero-warn{color:#f0a202;font-size:.8em;margin-top:8px}
        .hint{color:#888;font-size:.85em;line-height:1.5;margin-bottom:12px}
        .stock-forms{display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px}
        .stock-form{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
        .stock-form label{color:#95d5b2;font-size:.85em}
        .stock-form input[type="text"]{width:80px;padding:8px 10px;background:#0f3460;color:#eee;border:1px solid #333;border-radius:6px;font-size:.9em;font-family:inherit}
        .stock-form input[type="text"]:focus{outline:none;border-color:#e94560}
        .event-table{margin-top:16px;font-size:.85em}
        .event-table th{text-align:left;color:#888;font-weight:normal;padding:4px 6px;border-bottom:1px solid #0f3460}
        .event-table td{padding:6px;border-bottom:1px solid #0f3460;color:#ddd}
        .event-table .ev-del{color:#e94560;text-decoration:none}
        details.explain{margin-top:12px}
        details.explain summary{color:#95d5b2;font-size:.85em;cursor:pointer;-webkit-tap-highlight-color:transparent}
        details.explain .hint{margin:8px 0 0}
        .sstat-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
        .sstat-tabs button{padding:8px 14px;background:#0f3460;color:#eee;border:none;border-radius:6px;cursor:pointer;font-size:.85em;-webkit-tap-highlight-color:transparent}
        .sstat-tabs button.active,.sstat-tabs button:hover,.sstat-tabs button:active{background:#e94560}
        .sstat-wrap{position:relative;height:250px}
        .sstat-wrap canvas{display:none}
        .sstat-wrap canvas.active{display:block}
        @media(min-width:600px){.sstat-wrap{height:300px}}
        @media(min-width:900px){.sstat-wrap{height:350px}}
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
        .live-summary{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
        .live-card{border-radius:8px;padding:12px;text-align:center;position:relative}
        .live-card .s-label{font-size:.7em;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
        .live-card .s-val{font-size:2em;font-weight:bold;line-height:1.1}
        .live-card .s-unit{font-size:.4em;margin-left:3px;color:#888}
        .range-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
        .range-tabs button{padding:8px 14px;background:#0f3460;color:#eee;border:none;border-radius:6px;cursor:pointer;font-size:.85em;-webkit-tap-highlight-color:transparent}
        .range-tabs button.active,.range-tabs button:hover,.range-tabs button:active{background:#e94560}
        .pellet-vorrat{background:linear-gradient(135deg,#3a0d18,#1a0509);border:1px solid #e94560}
        .pellet-vorrat .s-label,.pellet-vorrat .s-val{color:#e94560}
        .pellet-lager{background:linear-gradient(135deg,#1b3a2e,#0f2419);border:1px solid #95d5b2}
        .pellet-lager .s-label,.pellet-lager .s-val{color:#95d5b2}
        .pellet-behaelter{background:linear-gradient(135deg,#3a2a00,#1a1200);border:1px solid #f0a202}
        .pellet-behaelter .s-label,.pellet-behaelter .s-val{color:#f0a202}
        .pellet-zaehler{background:linear-gradient(135deg,#0d2a4a,#071825);border:1px solid #64b5f6}
        .pellet-zaehler .s-label,.pellet-zaehler .s-val{color:#64b5f6}
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
        .live-chart-wrap{position:relative;height:280px}
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
            .live-chart-wrap{height:320px}
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
            .live-chart-wrap{height:360px}
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

        <?php $fmt = function($v) { return number_format((float)$v, 0, ',', '.'); }; ?>
        <div class="hero-card">
            <div class="hero-label">Vorrat gesamt</div>
            <?php if($stock['ok']):?>
                <div class="hero-value">
                    <?=$fmt($stock['total'])?>
                    <span class="hero-unit">kg</span>
                </div>
                <div class="hero-sub">
                    <?php if($stock['lager'] !== null):?>
                        Lager <?=$fmt($stock['lager'])?> kg + Behälter <?=$fmt($stock['hopper'])?> kg
                    <?php else:?>
                        Behälterinhalt nicht lesbar — Aufteilung unbekannt
                    <?php endif?>
                </div>
                <div class="hero-note">
                    Basis <?=date('d.m.Y', $stock['base']['ts'])?>:
                    <?=$fmt($stock['base']['kg'] + $stock['base']['hopper'])?> kg<?php
                    if($stock['sacks'] > 0):?> + <?=$fmt($stock['sacks'])?> kg Säcke<?php endif?>
                    − <?=$fmt($stock['burned'])?> kg verbrannt
                    <?php if($kesselLager !== null):?>
                        <br>Kessel meldet <?=htmlspecialchars($CONFIG['hero']['name'])?>: <?=$fmt($kesselLager)?> kg
                        <?php $abw = ($stock['lager'] !== null) ? $stock['lager'] - $kesselLager : null; ?>
                        <?php if($abw !== null && abs($abw) >= 1):?>
                            (<?=($abw > 0 ? '+' : '')?><?=$fmt($abw)?> kg Abweichung)
                        <?php endif?>
                    <?php endif?>
                </div>
                <?php if($stock['total'] < 0):?>
                    <div class="hero-warn">Bilanz ist negativ — es fehlt eine Befüllung oder ein Sack im Protokoll.</div>
                <?php endif?>
            <?php else:?>
                <div class="hero-value err">--</div>
                <div class="hero-sub"><?=htmlspecialchars($stock['reason'])?></div>
            <?php endif?>
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

        <div class="card">
            <h2>Vorrat nachtragen</h2>
            <div class="stock-forms">
                <form method="post" action="?action=addevent" class="stock-form">
                    <input type="hidden" name="type" value="sack">
                    <input type="hidden" name="kg" value="<?=htmlspecialchars((string)$CONFIG['sack_kg'])?>">
                    <button type="submit" class="btn">+ <?=htmlspecialchars((string)$CONFIG['sack_kg'])?> kg Sack</button>
                </form>
                <form method="post" action="?action=addevent" class="stock-form">
                    <input type="hidden" name="type" value="bestand">
                    <label>Lager befüllt auf</label>
                    <input type="text" name="kg" inputmode="decimal" placeholder="3600">
                    <span style="color:#888">kg</span>
                    <button type="submit" class="btn btn-outline">Eintragen</button>
                </form>
            </div>
            <?php if($events):?>
                <div class="table-scroll"><table class="event-table">
                    <tr><th>Zeitpunkt</th><th>Art</th><th>Menge</th><th>Zählerstand</th><th></th></tr>
                    <?php foreach(array_reverse($events, true) as $idx => $e):?>
                        <tr>
                            <td><?=htmlspecialchars($e['timestamp'])?></td>
                            <td><?=$e['type'] === 'bestand' ? 'Lager befüllt auf' : 'Sack'?></td>
                            <td><?=$fmt($e['kg'])?> kg</td>
                            <td><?=$fmt($e['counter'])?> kg</td>
                            <td><a href="?action=delevent&idx=<?=$idx?>" class="ev-del" title="Eintrag entfernen" onclick="return confirm('Eintrag entfernen?')">&times;</a></td>
                        </tr>
                    <?php endforeach?>
                </table></div>
            <?php endif?>
            <details class="explain">
                <summary>Was ist das?</summary>
                <p class="hint">
                    Der Kessel misst das Lager nicht, er rechnet nur: eingetragene Füllmenge minus
                    verbrannte kg. Säcke, die bei einem Klemmer direkt in den Behälter gekippt werden,
                    kennt er nicht — hier eingetragen, stimmt die Bilanz oben weiter.
                </p>
            </details>
        </div>

    <?php elseif($action==='verbrauch'):?>

        <div class="card">
            <h2>Pelletvorrat</h2>
            <?php
                $cur   = $stockSeries['current'];
                $fmtKg = fn($v) => $v !== null ? number_format((float)$v, 0, ',', '.') : '--';
            ?>
            <div class="live-summary">
                <div class="live-card pellet-vorrat">
                    <div class="s-label">Vorrat gesamt</div>
                    <div class="s-val"><?=$fmtKg($cur['total'] ?? null)?><span class="s-unit">kg</span></div>
                </div>
                <div class="live-card pellet-lager">
                    <div class="s-label">Lager</div>
                    <div class="s-val"><?=$fmtKg($cur['lager'] ?? null)?><span class="s-unit">kg</span></div>
                </div>
                <div class="live-card pellet-behaelter">
                    <div class="s-label">Behälter</div>
                    <div class="s-val"><?=$fmtKg($cur['hopper'] ?? null)?><span class="s-unit">kg</span></div>
                </div>
                <div class="live-card pellet-zaehler">
                    <div class="s-label"><?=htmlspecialchars($CONFIG['counter']['name'])?></div>
                    <div class="s-val"><?=$fmtKg($counterNowVerbr)?><span class="s-unit">kg</span></div>
                </div>
            </div>

            <?php if(!empty($stockSeries['labels'])):?>
                <div class="range-tabs">
                    <button class="active" onclick="setStockRange(24,this)">24 Stunden</button>
                    <button onclick="setStockRange(48,this)">48 Stunden</button>
                    <button onclick="setStockRange(168,this)">Woche</button>
                    <button onclick="setStockRange('month',this)">Monat</button>
                    <button onclick="setStockRange('year',this)">Jahr</button>
                </div>
                <p class="hint" id="stock-chart-note" style="margin:0 0 8px"></p>
                <div class="live-chart-wrap">
                    <canvas id="stock-chart"></canvas>
                </div>

                <script>
                const stockLabels    = <?=json_encode($stockSeries['labels'])?>;
                const stockSeries    = <?=json_encode($stockSeries['series'])?>;
                const stockDayLabels = <?=json_encode($stockDailySerie['labels'])?>;
                const stockDaySeries = <?=json_encode($stockDailySerie['series'])?>;
                let stockChart = null;

                function setStockRange(range, btn) {
                    document.querySelectorAll('.range-tabs button').forEach(b=>b.classList.remove('active'));
                    btn.classList.add('active');
                    buildStockChart(range);
                }

                function buildStockChart(range) {
                    // Stundenwerte fuer kurze Zeitraeume, Tageswerte fuer Monat und Jahr.
                    const daily  = (range === 'month' || range === 'year');
                    const days   = (range === 'month') ? 30 : 365;
                    const srcLab = daily ? stockDayLabels : stockLabels;
                    const srcSer = daily ? stockDaySeries : stockSeries;
                    const n      = srcLab.length;
                    const start  = Math.max(0, n - (daily ? days : range));
                    const labels = srcLab.slice(start);

                    const note = document.getElementById('stock-chart-note');
                    if (note) {
                        note.textContent = daily
                            ? 'Tageswerte (Stand am Tagesende) — ' + labels.length + ' Tage'
                            : '';
                    }

                    const datasets = srcSer.map(s => ({
                        label:           s.name,
                        data:            s.data.slice(start),
                        borderColor:     s.color,
                        backgroundColor: s.color + '18',
                        borderWidth:     2,
                        pointRadius:     (!daily && range <= 48) ? 3 : (daily && labels.length <= 40 ? 2 : 0),
                        pointHoverRadius:5,
                        fill:            false,
                        tension:         0.35,
                        spanGaps:        true
                    }));

                    if (stockChart) stockChart.destroy();
                    const ctx = document.getElementById('stock-chart').getContext('2d');
                    stockChart = new Chart(ctx, {
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
                                            return ctx.dataset.label + ': ' + (v !== null ? v.toFixed(0) + ' kg' : '--');
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
                                    ticks: { color:'#888', callback: v => v + ' kg' },
                                    grid:  { color:'rgba(255,255,255,0.08)' }
                                }
                            }
                        }
                    });
                }

                buildStockChart(24);
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch kein Vorratsverlauf vorhanden.<br>
                    <small>Dafür braucht es einen Bestands-Eintrag auf dem Dashboard und mindestens einen
                    geloggten Zählerstand.</small>
                </div>
            <?php endif?>
        </div>

        <div class="card">
            <h2>Verbrauchsstatistik</h2>
            <?php
                $hasConsumption = !empty($consumption['daily']) || !empty($consumption['weekly'])
                        || !empty($consumption['monthly']) || !empty($consumption['yearly']);
            ?>
            <?php if($hasConsumption):?>
                <?php
                    $consToday = $consumption['daily'][date('Y-m-d')]  ?? 0;
                    $consWeek  = $consumption['weekly'][date('o-\KW')] ?? 0;
                    $consMonth = $consumption['monthly'][date('Y-m')]  ?? 0;
                    $consYear  = $consumption['yearly'][date('Y')]     ?? 0;
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

                <details class="explain">
                    <summary>Was wird hier gezaehlt?</summary>
                    <p class="hint">
                        Basis ist der Zähler „<?=htmlspecialchars($CONFIG['counter']['name'])?>" — die
                        tatsächlich verbrannten kg. Der Rückgang des Lagerwerts taugt dafür nicht: der
                        Kessel verbrennt aus dem Behälter, der schubweise nachgesaugt wird, und an
                        Liefertagen fiele der Tagesverbrauch ganz aus.
                    </p>
                </details>

                <script>
                const chartData = {
                    daily:   {labels:<?=json_encode(array_keys($consumption['daily']))?>,   data:<?=json_encode(array_values($consumption['daily']))?>},
                    weekly:  {labels:<?=json_encode(array_keys($consumption['weekly']))?>,  data:<?=json_encode(array_values($consumption['weekly']))?>},
                    monthly: {labels:<?=json_encode(array_keys($consumption['monthly']))?>, data:<?=json_encode(array_values($consumption['monthly']))?>},
                    yearly:  {labels:<?=json_encode(array_keys($consumption['yearly']))?>,  data:<?=json_encode(array_values($consumption['yearly']))?>}
                };
                const charts = {};

                function makeChart(id) {
                    const ctx = document.getElementById('chart-'+id).getContext('2d');
                    charts[id] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: chartData[id].labels,
                            datasets: [{
                                label: 'Verbrauch (kg)',
                                data: chartData[id].data,
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
                                tooltip: {callbacks: {label: ctx => ctx.parsed.y + ' kg'}}
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
                    if (!charts[id]) makeChart(id);
                }

                if (chartData.daily.labels.length > 0) makeChart('daily');
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch keine Verbrauchsdaten vorhanden.<br>
                    <small>Der Cronjob muss den Zähler „<?=htmlspecialchars($CONFIG['counter']['name'])?>"
                    mindestens 2x geloggt haben, damit ein Verbrauch berechnet werden kann.</small>
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

            <div class="live-summary">
                <div class="live-card solar-kollektor">
                    <div class="s-label">Kollektor</div>
                    <div class="s-val"><?=$fmtVal($sc[$kollUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="live-card solar-aussen">
                    <div class="s-label">Außentemperatur</div>
                    <div class="s-val"><?=$fmtVal($sc[$aussenUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="live-card solar-puffer-oben">
                    <div class="s-label">Puffer oben</div>
                    <div class="s-val"><?=$fmtVal($sc[$pufOUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
                <div class="live-card solar-puffer-unten">
                    <div class="s-label">Puffer unten</div>
                    <div class="s-val"><?=$fmtVal($sc[$pufUUri] ?? null)?><span class="s-unit">°C</span></div>
                </div>
            </div>

            <?php if(!empty($solarTimeseries['labels'])):?>
                <div class="range-tabs">
                    <button class="active" onclick="setSolarRange(24,this)">24 Stunden</button>
                    <button onclick="setSolarRange(48,this)">48 Stunden</button>
                    <button onclick="setSolarRange(168,this)">Woche</button>
                    <button onclick="setSolarRange('month',this)">Monat</button>
                    <button onclick="setSolarRange('year',this)">Jahr</button>
                </div>
                <p class="hint" id="solar-chart-note" style="margin:0 0 8px"></p>
                <div class="live-chart-wrap">
                    <canvas id="solar-chart"></canvas>
                </div>

                <script>
                const solarAllLabels = <?=json_encode($solarTimeseries['labels'])?>;
                const solarSeries    = <?=json_encode(array_map(fn($s)=>['name'=>$s['name'],'data'=>$s['data'],'color'=>$s['color']], $solarTimeseries['series']))?>;
                const solarDayLabels = <?=json_encode($solarDaily['labels'])?>;
                const solarDaySeries = <?=json_encode(array_map(fn($s)=>['name'=>$s['name'],'data'=>$s['data'],'color'=>$s['color']], $solarDaily['series']))?>;
                let solarChart = null;

                function setSolarRange(range, btn) {
                    document.querySelectorAll('.range-tabs button').forEach(b=>b.classList.remove('active'));
                    btn.classList.add('active');
                    buildSolarChart(range);
                }

                function buildSolarChart(range) {
                    // Stundenwerte fuer kurze Zeitraeume, Tageshoechstwerte fuer Monat und Jahr.
                    const daily  = (range === 'month' || range === 'year');
                    const days   = (range === 'month') ? 30 : 365;
                    const srcLab = daily ? solarDayLabels : solarAllLabels;
                    const srcSer = daily ? solarDaySeries : solarSeries;
                    const n      = srcLab.length;
                    const start  = Math.max(0, n - (daily ? days : range));
                    const labels = srcLab.slice(start);

                    const note = document.getElementById('solar-chart-note');
                    if (note) {
                        note.textContent = daily
                            ? 'Tageshöchstwerte je Variable — ' + labels.length + ' Tage'
                            : '';
                    }

                    const datasets = srcSer.map(s => ({
                        label:           s.name,
                        data:            s.data.slice(start),
                        borderColor:     s.color,
                        backgroundColor: s.color + '18',
                        borderWidth:     2,
                        pointRadius:     (!daily && range <= 48) ? 3 : (daily && labels.length <= 40 ? 2 : 0),
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


        <div class="card">
            <h2>Solarstatistik</h2>
            <?php
                $sstatHas = !empty($solarStats['daily']) || !empty($solarStats['peakDaily']);
            ?>
            <?php if($sstatHas):?>
                <?php
                    $sToday = $solarStats['daily'][date('Y-m-d')]   ?? 0;
                    $sWeek  = $solarStats['weekly'][date('o-\KW')]  ?? 0;
                    $sMonth = $solarStats['monthly'][date('Y-m')]   ?? 0;
                    $sYear  = $solarStats['yearly'][date('Y')]      ?? 0;
                    $sh = fn($v) => number_format((float)$v, 1, ',', '.');
                ?>
                <div class="consumption-summary">
                    <div class="cons-item">
                        <div class="cons-label">Heute</div>
                        <div class="cons-val"><?=$sh($sToday)?> <span class="cons-unit">h</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Diese Woche</div>
                        <div class="cons-val"><?=$sh($sWeek)?> <span class="cons-unit">h</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Dieser Monat</div>
                        <div class="cons-val"><?=$sh($sMonth)?> <span class="cons-unit">h</span></div>
                    </div>
                    <div class="cons-item">
                        <div class="cons-label">Dieses Jahr</div>
                        <div class="cons-val"><?=$sh($sYear)?> <span class="cons-unit">h</span></div>
                    </div>
                </div>

                <div class="sstat-tabs">
                    <button class="active" onclick="showSolarStat('daily',this)">Taeglich</button>
                    <button onclick="showSolarStat('weekly',this)">Woechentlich</button>
                    <button onclick="showSolarStat('monthly',this)">Monatlich</button>
                    <button onclick="showSolarStat('yearly',this)">Jaehrlich</button>
                    <button onclick="showSolarStat('peak',this)">Spitzentemperatur</button>
                </div>
                <div class="sstat-wrap">
                    <canvas id="sstat-daily" class="active"></canvas>
                    <canvas id="sstat-weekly"></canvas>
                    <canvas id="sstat-monthly"></canvas>
                    <canvas id="sstat-yearly"></canvas>
                    <canvas id="sstat-peak"></canvas>
                </div>

                <details class="explain">
                    <summary>Was wird hier gezaehlt?</summary>
                    <p class="hint">
                        Sonnenstunden = Zeit, in der der Kollektor über der Starttemperatur von
                        <?=htmlspecialchars((string)$CONFIG['solar_stats']['threshold'])?>&nbsp;°C lag, also
                        liefern konnte. Ein Ertrag in kWh lässt sich daraus nicht ableiten — der Kessel hat
                        keinen Ertragszähler. Bei vollem Puffer steht die Pumpe, die Stunde zählt trotzdem.
                        Seit dem <?=date('d.m.Y')?> werden zusätzlich Kollektorpumpe und Speicherfühler
                        geloggt; sobald davon genug Historie da ist, kann die Statistik auf die tatsächliche
                        Pumpenlaufzeit umgestellt werden.
                    </p>
                </details>

                <script>
                const sstatData = {
                    daily:   {labels:<?=json_encode(array_keys($solarStats['daily']))?>,     data:<?=json_encode(array_values($solarStats['daily']))?>,     unit:' h'},
                    weekly:  {labels:<?=json_encode(array_keys($solarStats['weekly']))?>,    data:<?=json_encode(array_values($solarStats['weekly']))?>,    unit:' h'},
                    monthly: {labels:<?=json_encode(array_keys($solarStats['monthly']))?>,   data:<?=json_encode(array_values($solarStats['monthly']))?>,   unit:' h'},
                    yearly:  {labels:<?=json_encode(array_keys($solarStats['yearly']))?>,    data:<?=json_encode(array_values($solarStats['yearly']))?>,    unit:' h'},
                    peak:    {labels:<?=json_encode(array_keys($solarStats['peakDaily']))?>, data:<?=json_encode(array_values($solarStats['peakDaily']))?>, unit:' °C'}
                };
                const sstatCharts = {};

                function makeSolarStat(id) {
                    const d      = sstatData[id];
                    const isPeak = (id === 'peak');
                    const ctx    = document.getElementById('sstat-'+id).getContext('2d');
                    sstatCharts[id] = new Chart(ctx, {
                        type: isPeak ? 'line' : 'bar',
                        data: {
                            labels: d.labels,
                            datasets: [{
                                label: isPeak ? 'Spitzentemperatur' : 'Sonnenstunden',
                                data: d.data,
                                backgroundColor: isPeak ? 'rgba(240,162,2,0.15)' : 'rgba(240,162,2,0.7)',
                                borderColor: '#f0a202',
                                borderWidth: isPeak ? 2 : 1,
                                borderRadius: isPeak ? 0 : 4,
                                fill: isPeak,
                                tension: 0.3,
                                pointRadius: isPeak ? 3 : undefined
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {display: false},
                                tooltip: {callbacks: {label: ctx => ctx.parsed.y + d.unit}}
                            },
                            scales: {
                                x: {ticks:{color:'#888',maxRotation:45},grid:{color:'rgba(255,255,255,0.05)'}},
                                y: {beginAtZero:!isPeak,ticks:{color:'#888',callback:v => v + d.unit},grid:{color:'rgba(255,255,255,0.08)'}}
                            }
                        }
                    });
                }

                function showSolarStat(id, btn) {
                    document.querySelectorAll('.sstat-tabs button').forEach(b=>b.classList.remove('active'));
                    document.querySelectorAll('.sstat-wrap canvas').forEach(c=>c.classList.remove('active'));
                    btn.classList.add('active');
                    document.getElementById('sstat-'+id).classList.add('active');
                    if (!sstatCharts[id]) makeSolarStat(id);
                }

                if (sstatData.daily.labels.length > 0) makeSolarStat('daily');
                </script>
            <?php else:?>
                <div class="no-data">
                    Noch keine Solardaten für eine Statistik vorhanden.<br>
                    <small>Sobald der stündliche Cronjob die Kollektortemperatur geloggt hat, erscheinen hier die Sonnenstunden.</small>
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
                <h3 style="color:#95d5b2;font-size:.95em;margin-bottom:4px">Vorratsbilanz</h3>
                <p style="color:#888;font-size:.8em;margin-bottom:10px">Zähler und Behälter sind die Basis für Verbrauch und Vorratsanzeige. Sie werden immer geloggt, auch ohne eigene Kachel.</p>
                <div class="tile-row" style="grid-template-columns:1fr 2fr">
                    <div>
                        <label>Zähler (verbrannte kg)</label>
                        <input type="text" name="counter_name" value="<?=htmlspecialchars($CONFIG['counter']['name'])?>">
                    </div>
                    <div>
                        <label>URI-Pfad</label>
                        <input type="text" name="counter_uri" value="<?=htmlspecialchars($CONFIG['counter']['uri'])?>">
                    </div>
                </div>
                <div class="tile-row" style="grid-template-columns:1fr 2fr">
                    <div>
                        <label>Vorratsbehälter</label>
                        <input type="text" name="hopper_name" value="<?=htmlspecialchars($CONFIG['hopper']['name'])?>">
                    </div>
                    <div>
                        <label>URI-Pfad</label>
                        <input type="text" name="hopper_uri" value="<?=htmlspecialchars($CONFIG['hopper']['uri'])?>">
                    </div>
                </div>
                <div class="tile-row" style="grid-template-columns:1fr 2fr">
                    <div>
                        <label>Sackgröße (kg)</label>
                        <input type="text" name="sack_kg" value="<?=htmlspecialchars((string)$CONFIG['sack_kg'])?>">
                    </div>
                    <div></div>
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
