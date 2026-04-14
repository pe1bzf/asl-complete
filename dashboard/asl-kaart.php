<?php
date_default_timezone_set("Europe/Amsterdam");
/**
 * AllStarLink Kaart + Activiteit — compacte weergave
 * Toont locatiekaart prominent + laatste 15 activiteitsregels.
 * URL: /asl/asl/
 */

$LOG_FILE    = '/var/log/asterisk/rpt-activity.log';
$TOPO_FILE   = '/var/log/asterisk/asl-current-topo.json';
$EL_DIR_FILE = '/var/log/asterisk/echolink-dir.json';
$MY_NODE     = '449581';
$MY_CALL     = 'PE1BZF';
$ASL_API     = 'https://stats.allstarlink.org/api/stats/' . $MY_NODE;
$CACHE_FILE  = '/tmp/asl_api_cache.json';
$CACHE_TTL   = 60;

// ── SSE stream ────────────────────────────────────────────────────────────────
if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    set_time_limit(0);
    ignore_user_abort(false);

    $state   = parseLogWithState($LOG_FILE);
    $initial = $state['data'];
    $initial['tx_keys']   = array_map('strval', array_keys($state['txState']));
    $initial['link_keys'] = array_map('strval', array_keys($state['linkState']));
    echo "data: " . json_encode($initial) . "\n\n";
    ob_flush(); flush();

    $fp        = fopen($LOG_FILE, 'r');
    fseek($fp, 0, SEEK_END);
    $lastSize  = ftell($fp);
    $heartbeat = time();
    $txState   = $state['txState'];
    $linkState = $state['linkState'];
    $lastTx    = $state['lastTx'];
    $recent    = $state['data']['recent'];

    while (!connection_aborted()) {
        clearstatcache(true, $LOG_FILE);
        $newSize = @filesize($LOG_FILE);

        if ($newSize > $lastSize) {
            fseek($fp, $lastSize);
            $chunk    = fread($fp, $newSize - $lastSize);
            $lastSize = $newSize;
            $changed  = false;

            foreach (explode("\n", $chunk) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) continue;
                $ts = $m[1];

                preg_match('/\[(TX-AAN|TX-UIT|LINK|UNLINK|INFO|WARN)\]/', $line, $em);
                $type = $em[1] ?? 'MSG';

                [$nodeKey, $nodeLabel] = extractNode($line);
                if (strpos($nodeKey, 'DAHDI') !== false) continue;

                if ($type === 'TX-AAN' && $nodeKey !== '') {
                    $txState[$nodeKey] = ['ts' => $ts, 'label' => $nodeLabel];
                    $changed = true;
                } elseif ($type === 'TX-UIT' && $nodeKey !== '') {
                    if (isset($txState[$nodeKey])) {
                        array_unshift($lastTx, ['label' => $nodeLabel, 'started' => $txState[$nodeKey]['ts'], 'ended' => $ts]);
                        $lastTx = array_slice($lastTx, 0, 5);
                        unset($txState[$nodeKey]);
                    }
                    $changed = true;
                } elseif ($type === 'LINK' && $nodeKey !== '') {
                    $linkState[$nodeKey] = ['ts' => $ts, 'label' => $nodeLabel];
                    $changed = true;
                } elseif ($type === 'UNLINK' && $nodeKey !== '') {
                    unset($linkState[$nodeKey]);
                    if (isset($txState[$nodeKey])) {
                        array_unshift($lastTx, ['label' => $nodeLabel, 'started' => $txState[$nodeKey]['ts'], 'ended' => $ts]);
                        $lastTx = array_slice($lastTx, 0, 5);
                        unset($txState[$nodeKey]);
                    }
                    $changed = true;
                } elseif (in_array($type, ['INFO', 'WARN', 'MSG'])) {
                    $changed = true;
                }

                $text = trim(preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(\[.*?\])?\s*/', '', $line));
                array_unshift($recent, ['ts' => $ts, 'type' => $type, 'text' => $text]);
                $recent = array_slice($recent, 0, 15);
            }

            if ($changed) {
                echo "data: " . json_encode([
                    'updated'   => date('H:i:s'),
                    'tx_active' => array_values($txState),
                    'linked'    => array_values($linkState),
                    'recent'    => $recent,
                    'tx_keys'   => array_map('strval', array_keys($txState)),
                    'link_keys' => array_map('strval', array_keys($linkState)),
                ]) . "\n\n";
                ob_flush(); flush();
                $heartbeat = time();
            }
        }

        if (time() - $heartbeat >= 20) {
            echo ": heartbeat\n\n";
            ob_flush(); flush();
            $heartbeat = time();
        }

        usleep(100000);
    }
    fclose($fp);
    exit;
}

// ── One-shot API ──────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $state = parseLogWithState($LOG_FILE);
    $d = $state['data'];
    $d['tx_keys']   = array_map('strval', array_keys($state['txState']));
    $d['link_keys'] = array_map('strval', array_keys($state['linkState']));
    echo json_encode($d);
    exit;
}

// ── Netmap / kaartdata ────────────────────────────────────────────────────────
if (isset($_GET['netmap'])) {
    header('Content-Type: application/json');

    $apiData = [];
    if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
        $apiData = json_decode(file_get_contents($CACHE_FILE), true) ?? [];
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: ASL-Dashboard/1.0\r\n"]]);
        $raw = @file_get_contents($ASL_API, false, $ctx);
        if ($raw) {
            file_put_contents($CACHE_FILE, $raw);
            $apiData = json_decode($raw, true) ?? [];
        }
    }

    $state        = parseLogWithState($LOG_FILE);
    $txKeys       = array_keys($state['txState']);
    $linkKeys     = array_keys($state['linkState']);
    $unlinkedKeys = array_keys($state['unlinkedState']); // vandaag expliciet ontkoppeld
    $linkLabels   = array_map(fn($n) => $n['label'], $state['linkState']);
    $txLabels     = array_map(fn($n) => $n['label'], $state['txState']);

    $topo = json_decode(@file_get_contents($TOPO_FILE), true) ?? [];

    $apiCallsigns = [];
    foreach ($apiData['stats']['data']['linkedNodes'] ?? [] as $ln) {
        $nid = (string)($ln['name'] ?? '');
        $cs  = $ln['callsign'] ?? '';
        if ($nid !== '' && $cs !== '') $apiCallsigns[$nid] = $cs;
    }

    $elDir = json_decode(@file_get_contents($EL_DIR_FILE) ?: '{}', true) ?? [];

    $resolveLabel = function(string $id) use ($linkLabels, $txLabels, $apiCallsigns, $elDir): string {
        foreach ([$linkLabels[$id] ?? null, $txLabels[$id] ?? null] as $candidate) {
            if ($candidate !== null && strpos($candidate, '(?)') === false) return $candidate;
        }
        if (isset($apiCallsigns[$id])) return "$id\n{$apiCallsigns[$id]}";
        if (strlen($id) === 7 && $id[0] === '3' && ctype_digit($id)) {
            $elNum = ltrim(substr($id, 1), '0') ?: '0';
            $call  = $elDir[$elNum] ?? $elDir[substr($id, 1)] ?? null;
            if ($call) return "$id\nEL:$call";
        }
        return $id;
    };

    $nodes = [];
    $seen  = [];

    $myServer = $apiData['stats']['data']['server'] ?? [];
    $myLat = (float)($myServer['Latitude'] ?? 0);
    $myLon = (float)($myServer['Logitude'] ?? 0);

    $nodes[] = [
        'id'    => $MY_NODE,
        'label' => $MY_NODE . "\n" . $MY_CALL,
        'group' => 'self',
        'title' => 'Eigen hub node',
        'lat'   => $myLat ?: null,
        'lon'   => $myLon ?: null,
    ];
    $seen[$MY_NODE] = true;

    foreach ($apiData['stats']['data']['linkedNodes'] ?? [] as $ln) {
        $id       = (string)($ln['name'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;
        $callsign = $ln['callsign'] ?? '';
        $location = $ln['server']['Location'] ?? '';
        $freq     = $ln['node_frequency'] ?? '';
        $status   = $ln['Status'] ?? '';

        $inTx = in_array($id, $txKeys);

        // Log wint van API-cache: als deze node vandaag expliciet is losgekoppeld
        // (en niet heraangesloten), tonen we hem niet — ook al staat hij nog in de cache.
        if (!$inTx && in_array($id, $unlinkedKeys)) continue;

        // Nodes in de ASL API linkedNodes zijn per definitie verbonden.
        // Niet afhankelijk van het log (dat na middernacht-rotatie de pre-midnight
        // LINK-events kwijt is), maar van de live API-respons.
        $group = $inTx ? 'tx' : 'linked';

        $title = $id;
        if ($callsign) $title .= " · $callsign";
        if ($location) $title .= "\n$location";
        if ($freq && $freq !== 'Android') $title .= "\n$freq";

        $lat = (float)($ln['server']['Latitude'] ?? 0);
        $lon = (float)($ln['server']['Logitude'] ?? 0);

        $nodes[] = [
            'id'    => $id,
            'label' => $id . ($callsign ? "\n$callsign" : ''),
            'group' => $group,
            'title' => $title,
            'lat'   => $lat ?: null,
            'lon'   => $lon ?: null,
        ];
        $seen[$id] = true;
    }

    foreach ($linkKeys as $id) {
        if (isset($seen[$id])) continue;
        $inTx  = in_array($id, $txKeys);
        $label = $resolveLabel($id);
        $nodes[] = ['id' => $id, 'label' => $label, 'group' => $inTx ? 'tx' : 'linked', 'title' => $label, 'lat' => null, 'lon' => null];
        $seen[$id] = true;
    }

    echo json_encode([
        'nodes'     => $nodes,
        'tx_keys'   => $txKeys,
        'link_keys' => $linkKeys,
    ]);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function extractNode($line) {
    if (preg_match('/node=(\S+)\s+\(([^)]+)\)/', $line, $nm)) {
        return [$nm[1], $nm[1] . ' (' . $nm[2] . ')'];
    } elseif (preg_match('/node=(\S+)/', $line, $nm)) {
        return [$nm[1], $nm[1]];
    }
    return ['', ''];
}

function parseLogWithState($file) {
    if (!file_exists($file)) {
        return ['data' => ['error' => 'Logbestand niet gevonden'], 'txState' => [], 'linkState' => [], 'lastTx' => [], 'recent' => []];
    }

    $lines         = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recent        = [];
    $txState       = [];
    $lastTx        = [];
    $linkState     = [];
    $unlinkedState = []; // nodes die vandaag expliciet losgekoppeld zijn (en niet heraangesloten)

    foreach ($lines as $line) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) continue;
        $ts = $m[1];

        preg_match('/\[(TX-AAN|TX-UIT|LINK|UNLINK|INFO|WARN)\]/', $line, $em);
        $type = $em[1] ?? 'MSG';

        [$nodeKey, $nodeLabel] = extractNode($line);
        if (strpos($nodeKey, 'DAHDI') !== false) continue;

        if ($type === 'TX-AAN' && $nodeKey !== '') {
            $txState[$nodeKey] = ['ts' => $ts, 'label' => $nodeLabel];
        } elseif ($type === 'TX-UIT' && $nodeKey !== '') {
            if (isset($txState[$nodeKey])) {
                $lastTx[] = ['label' => $nodeLabel, 'started' => $txState[$nodeKey]['ts'], 'ended' => $ts];
                unset($txState[$nodeKey]);
            }
        } elseif ($type === 'LINK' && $nodeKey !== '') {
            $linkState[$nodeKey] = ['ts' => $ts, 'label' => $nodeLabel];
            unset($unlinkedState[$nodeKey]); // heraangesloten: niet langer expliciet weg
        } elseif ($type === 'UNLINK' && $nodeKey !== '') {
            unset($linkState[$nodeKey]);
            $unlinkedState[$nodeKey] = true; // expliciet losgekoppeld vandaag
            if (isset($txState[$nodeKey])) {
                $lastTx[] = ['label' => $nodeLabel, 'started' => $txState[$nodeKey]['ts'], 'ended' => $ts];
                unset($txState[$nodeKey]);
            }
        }

        $text = trim(preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(\[.*?\])?\s*/', '', $line));
        $recent[] = ['ts' => $ts, 'type' => $type, 'text' => $text];
    }

    $recent = array_slice(array_reverse($recent), 0, 15);
    $lastTx = array_slice(array_reverse($lastTx), 0, 5);

    return [
        'data'         => ['updated' => date('H:i:s'), 'tx_active' => array_values($txState), 'last_tx' => $lastTx, 'linked' => array_values($linkState), 'recent' => $recent],
        'txState'      => $txState,
        'linkState'    => $linkState,
        'unlinkedState'=> $unlinkedState,
        'lastTx'       => $lastTx,
        'recent'       => $recent,
    ];
}

$state = parseLogWithState($LOG_FILE);
$data  = $state['data'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASL Kaart — <?= htmlspecialchars($MY_CALL) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .pulse { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
        #node-map { height: clamp(260px, 55vw, 520px); border-radius: 0.5rem; }
        .leaflet-popup-content-wrapper { background: #1f2937; color: #f3f4f6; border: 1px solid #374151; }
        .leaflet-popup-tip { background: #1f2937; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen font-mono text-sm">

<div class="max-w-5xl mx-auto px-3 py-4 sm:px-4 sm:py-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-2">
        <div>
            <h1 class="text-xl font-bold text-white">AllStarLink — <?= htmlspecialchars($MY_CALL) ?></h1>
            <p class="text-gray-500 text-xs mt-0.5">Node <?= htmlspecialchars($MY_NODE) ?> · live kaart &amp; activiteit</p>
        </div>
        <div class="flex items-center gap-2 text-xs text-gray-400 flex-wrap">
            <span id="status-dot" class="w-2.5 h-2.5 rounded-full bg-green-400 pulse inline-block"></span>
            <span>Bijgewerkt: <span id="updated">--:--:--</span></span>
            <a href="asl-compleet/" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-700 hover:bg-gray-600 border border-gray-600 text-gray-200 hover:text-white transition-colors text-xs font-medium"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>Volledig dashboard</a>
        </div>
    </div>

    <!-- TX banner (alleen zichtbaar als iemand uitzendt) -->
    <div id="tx-banner" class="hidden mb-4 bg-green-900/40 border border-green-700 rounded-xl px-4 py-3 flex items-center gap-3">
        <span class="w-3 h-3 rounded-full bg-green-400 pulse inline-block flex-shrink-0"></span>
        <span class="font-bold text-green-300">Aan het uitzenden:</span>
        <span id="tx-label" class="text-white"></span>
    </div>

    <!-- Locatiekaart — prominent -->
    <div class="bg-gray-800 rounded-xl border border-gray-700 mb-5">
        <div class="px-4 py-3 border-b border-gray-700">
            <div class="flex items-center justify-between">
                <h2 class="font-bold text-green-400 text-base">Locatiekaart</h2>
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-400 mt-1.5">
                <span class="flex items-center gap-1"><span class="inline-block w-2.5 h-2.5 rounded-full bg-cyan-400"></span>Eigen node</span>
                <span class="flex items-center gap-1"><span class="inline-block w-2.5 h-2.5 rounded-full bg-green-400"></span>Aan het uitzenden</span>
                <span class="flex items-center gap-1"><span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-400"></span>Verbonden</span>
                <span class="flex items-center gap-1"><span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-500"></span>Inactief</span>
            </div>
        </div>
        <div class="p-2 sm:p-3">
            <div id="node-map" class="border border-gray-700"></div>
        </div>
    </div>

    <!-- Recente activiteit — max 15 regels -->
    <div class="bg-gray-800 rounded-xl border border-gray-700">
        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between">
            <h2 class="font-bold text-base">Recente activiteit</h2>
            <span class="text-gray-500 text-xs">laatste 15 regels</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-400 border-b border-gray-700">
                        <th class="px-2 sm:px-4 py-2 text-left w-16 sm:w-20">Tijd</th>
                        <th class="px-2 sm:px-4 py-2 text-left w-20 sm:w-24">Type</th>
                        <th class="px-2 sm:px-4 py-2 text-left">Bericht</th>
                    </tr>
                </thead>
                <tbody id="activity-log" class="divide-y divide-gray-700/50">
                    <tr><td colspan="3" class="px-4 py-4 text-gray-500 italic">Laden...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const TYPE_COLORS = {
    'TX-AAN': 'bg-green-600 text-white',
    'TX-UIT': 'bg-gray-600 text-white',
    'LINK':   'bg-blue-600 text-white',
    'UNLINK': 'bg-orange-500 text-white',
    'INFO':   'bg-sky-600 text-white',
    'WARN':   'bg-yellow-500 text-gray-900',
    'MSG':    'bg-gray-700 text-gray-300',
};

const MAP_COLORS = {
    self:     '#06b6d4',
    tx:       '#22c55e',
    linked:   '#3b82f6',
    known:    '#6b7280',
    indirect: '#4b5563',
    inactive: '#374151',
};

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ── Activiteit ────────────────────────────────────────────────────────────────
let lastLinkKeys = [];

function applyData(data) {
    document.getElementById('updated').textContent = data.updated || '--';

    // Herlaad kaart als verbonden nodes zijn gewijzigd
    const newLinkKeys = (data.link_keys || []).map(String).sort().join(',');
    if (newLinkKeys !== lastLinkKeys.slice().sort().join(',')) {
        loadMap();
    }
    lastLinkKeys = data.link_keys || [];

    // TX banner
    const banner = document.getElementById('tx-banner');
    if (data.tx_active && data.tx_active.length) {
        banner.classList.remove('hidden');
        document.getElementById('tx-label').textContent =
            data.tx_active.map(n => n.label).join('  ·  ');
    } else {
        banner.classList.add('hidden');
    }

    // Activiteitstabel
    if (data.recent && data.recent.length) {
        document.getElementById('activity-log').innerHTML = data.recent.slice(0, 15).map(e => {
            const rowClass = e.type === 'TX-AAN'  ? 'bg-green-900/20'  :
                             e.type === 'LINK'    ? 'bg-blue-900/20'   :
                             e.type === 'UNLINK'  ? 'bg-orange-900/20' :
                             e.type === 'WARN'    ? 'bg-yellow-900/20' : '';
            const tc = TYPE_COLORS[e.type] || TYPE_COLORS['MSG'];
            const time = e.ts.split(' ')[1] || e.ts;
            return `<tr class="${rowClass}">
                <td class="px-2 sm:px-4 py-1.5 text-gray-400 whitespace-nowrap">${escHtml(time)}</td>
                <td class="px-2 sm:px-4 py-1.5"><span class="px-1.5 py-0.5 rounded text-xs font-bold ${tc}">${escHtml(e.type)}</span></td>
                <td class="px-2 sm:px-4 py-1.5 text-gray-200 break-words">${escHtml(e.text)}</td>
            </tr>`;
        }).join('');
    }

    document.getElementById('status-dot').classList.replace('bg-red-400', 'bg-green-400');

    // Kaartmarkers bijwerken
    if (leafletMap) {
        updateMapStatus(data.tx_keys || [], data.link_keys || []);
    }
}

// ── Locatiekaart ──────────────────────────────────────────────────────────────
let leafletMap = null;
let mapMarkers    = {};
let mapNodeGroups = {};
let mapFitted     = false;

function makeMarkerIcon(color) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="24" height="36">
        <path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24C24 5.4 18.6 0 12 0z"
              fill="${color}" stroke="#fff" stroke-width="1.5"/>
        <circle cx="12" cy="12" r="5" fill="#fff" fill-opacity="0.8"/>
    </svg>`;
    return L.divIcon({ html: svg, className: '', iconSize: [24, 36], iconAnchor: [12, 36], popupAnchor: [0, -36] });
}

function initMap(nodes) {
    const withCoords = nodes.filter(n => n.lat && n.lon);
    if (!withCoords.length) return;

    if (!leafletMap) {
        leafletMap = L.map('node-map', { zoomControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18,
        }).addTo(leafletMap);
    }

    Object.values(mapMarkers).forEach(m => m.remove());
    mapMarkers    = {};
    mapNodeGroups = {};

    const bounds = [];
    withCoords.forEach(n => {
        const color  = MAP_COLORS[n.group] || MAP_COLORS.known;
        const marker = L.marker([n.lat, n.lon], { icon: makeMarkerIcon(color) })
            .bindPopup(`<b>${escHtml(n.label.replace('\n', ' · '))}</b><br>${escHtml(n.title || '')}`)
            .addTo(leafletMap);
        mapMarkers[n.id]    = marker;
        mapNodeGroups[n.id] = n.group;
        bounds.push([n.lat, n.lon]);
    });

    if (bounds.length && !mapFitted) {
        leafletMap.fitBounds(bounds, { padding: [40, 40] });
        mapFitted = true;
    }
}

function updateMapStatus(txKeys, linkKeys) {
    const txSet   = new Set(txKeys.map(String));
    const linkSet = new Set(linkKeys.map(String));
    Object.entries(mapMarkers).forEach(([id, marker]) => {
        const initialGroup = mapNodeGroups[id] || 'known';
        const group = id === '<?= $MY_NODE ?>' ? 'self'
            : txSet.has(id)   ? 'tx'
            : linkSet.has(id) ? 'linked'
            : initialGroup;
        marker.setIcon(makeMarkerIcon(MAP_COLORS[group] || MAP_COLORS.known));
    });
}

async function loadMap() {
    try {
        const res  = await fetch('?netmap=1');
        const data = await res.json();
        initMap(data.nodes);
        updateMapStatus(data.tx_keys || [], data.link_keys || []);
    } catch(_) {}
}

// ── SSE ───────────────────────────────────────────────────────────────────────
function startSSE() {
    if (!window.EventSource) { startPolling(); return; }
    const es = new EventSource('?stream=1');
    es.onmessage = e => { try { applyData(JSON.parse(e.data)); } catch(_){} };
    es.onerror = () => {
        es.close();
        document.getElementById('status-dot').classList.replace('bg-green-400', 'bg-red-400');
        setTimeout(startSSE, 5000);
    };
}

function startPolling() {
    const poll = async () => {
        try { applyData(await (await fetch('?api=1')).json()); } catch(_){}
    };
    poll();
    setInterval(poll, 2000);
}

startSSE();
loadMap();
setInterval(loadMap, 60000);
</script>
</body>
</html>
