<?php
date_default_timezone_set("Europe/Amsterdam");
/**
 * AllStarLink Activity Dashboard
 * Reads /var/log/asterisk/rpt-activity.log and shows live activity + network map
 */

$LOG_FILE    = '/var/log/asterisk/rpt-activity.log';
$TOPO_FILE   = '/var/log/asterisk/asl-current-topo.json';
$EL_DIR_FILE = '/var/log/asterisk/echolink-dir.json';
$MY_NODE     = 'CHANGE_ME_NODE';
$MY_CALL     = 'N0CALL';
$ASL_API     = 'https://stats.allstarlink.org/api/stats/' . $MY_NODE;
$CACHE_FILE  = '/tmp/asl_api_cache.json';
$CACHE_TTL   = 300; // 5 minutes

// ── SSE stream mode — tail-f style ───────────────────────────────────────────
if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    set_time_limit(0);
    ignore_user_abort(false);

    $state = parseLogWithState($LOG_FILE);
    echo "data: " . json_encode($state['data']) . "\n\n";
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
                $recent = array_slice($recent, 0, 100);
            }

            if ($changed) {
                echo "data: " . json_encode([
                    'updated'   => date('H:i:s'),
                    'tx_active' => array_values($txState),
                    'last_tx'   => $lastTx,
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

// ── Network map data ──────────────────────────────────────────────────────────
if (isset($_GET['netmap'])) {
    header('Content-Type: application/json');

    // Fetch ASL API with cache
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

    // Current state from log
    $state        = parseLogWithState($LOG_FILE);
    $txKeys       = array_keys($state['txState']);
    $linkKeys     = array_keys($state['linkState']);
    $unlinkedKeys = array_keys($state['unlinkedState']); // vandaag expliciet ontkoppeld
    $linkLabels   = array_map(fn($n) => $n['label'], $state['linkState']);
    $txLabels     = array_map(fn($n) => $n['label'], $state['txState']);

    // Topology (indirect nodes)
    $topo = json_decode(@file_get_contents($TOPO_FILE), true) ?? [];

    // Roepnamen uit ASL API als fallback (gevuld voordat we door linkedNodes lopen)
    $apiCallsigns = [];
    foreach ($apiData['stats']['data']['linkedNodes'] ?? [] as $ln) {
        $nid = (string)($ln['name'] ?? '');
        $cs  = $ln['callsign'] ?? '';
        if ($nid !== '' && $cs !== '') $apiCallsigns[$nid] = $cs;
    }

    // EchoLink directory (geschreven door de Python logger, elke 5 min)
    $elDir = json_decode(@file_get_contents($EL_DIR_FILE) ?: '{}', true) ?? [];

    // Geef het opgeloste label terug: logstate → ASL API → EchoLink dir → puur ID
    // Labels met '(?)' worden genegeerd zodat de fallback kan werken
    $resolveLabel = function(string $id) use ($linkLabels, $txLabels, $apiCallsigns, $elDir): string {
        foreach ([$linkLabels[$id] ?? null, $txLabels[$id] ?? null] as $candidate) {
            if ($candidate !== null && strpos($candidate, '(?)') === false) {
                return $candidate;
            }
        }
        if (isset($apiCallsigns[$id])) return "$id\n{$apiCallsigns[$id]}";
        // EchoLink node: 3xxxxxx → strip '3', zoek in directory
        if (strlen($id) === 7 && $id[0] === '3' && ctype_digit($id)) {
            $elNum = ltrim(substr($id, 1), '0') ?: '0';
            $call  = $elDir[$elNum] ?? $elDir[substr($id, 1)] ?? null;
            if ($call) return "$id\nEL:$call";
        }
        return $id;
    };

    // Build nodes and edges
    $nodes = [];
    $edges = [];
    $seen  = [];

    // Eigen node coördinaten uit API
    $myServer = $apiData['stats']['data']['server'] ?? [];
    $myLat = (float)($myServer['Latitude'] ?? 0);
    $myLon = (float)($myServer['Logitude'] ?? 0);

    // Central node
    $nodes[] = [
        'id'    => $MY_NODE,
        'label' => $MY_NODE . "\n" . $MY_CALL,
        'group' => 'self',
        'title' => 'Eigen hub node',
        'lat'   => $myLat ?: null,
        'lon'   => $myLon ?: null,
    ];
    $seen[$MY_NODE] = true;

    // Direct linked nodes from ASL API
    $linkedNodes = $apiData['stats']['data']['linkedNodes'] ?? [];

    // Pre-bouw set van directe API-node-ID's: deze nodes worden altijd als directe
    // verbinding getoond met GPS-coördinaten, ook al staan ze in de topologie als
    // sub-node van een andere hub (bijv. PA2TSL staat in topo als sub-node van PA2JM,
    // maar IS rechtstreeks verbonden met 449581).
    $apiNodeSet = [];
    foreach ($linkedNodes as $ln) {
        $nid = (string)($ln['name'] ?? '');
        if ($nid !== '') $apiNodeSet[$nid] = true;
    }

    foreach ($linkedNodes as $ln) {
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

        // Nodes in de ASL API linkedNodes-lijst zijn per definitie verbonden.
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
        $edges[] = ['from' => $id, 'to' => $MY_NODE, 'id' => "e_$id"];
        $seen[$id] = true;

        // Sub-nodes from topology — skip als hub meer dan 10 sub-nodes heeft
        $subNodes = $topo[$id] ?? [];
        // Verwijder sub-nodes die ook directe API-nodes zijn: die worden al
        // direct aan de hub getoond met correcte GPS-coördinaten.
        $subNodes = array_filter($subNodes, fn($s) => !isset($apiNodeSet[(string)$s]));
        $subCount = count($subNodes);
        if ($subCount <= 10) {
            foreach ($subNodes as $sub) {
                $sub = (string)$sub;
                if ($sub === $id || isset($seen[$sub])) continue;
                $inTxSub  = in_array($sub, $txKeys);
                $subLabel = $resolveLabel($sub);
                $nodes[] = [
                    'id'    => $sub,
                    'label' => $subLabel,
                    'group' => $inTxSub ? 'tx' : 'indirect',
                    'title' => "$subLabel (via $id)",
                ];
                $edges[] = ['from' => $sub, 'to' => $id, 'id' => "e_{$sub}_{$id}"];
                $seen[$sub] = true;
            }
        } else {
            // Toon aantal verborgen sub-nodes op de bubbel
            $last = count($nodes) - 1;
            $nodes[$last]['label'] .= "\n{$subCount}+ nodes";
            $nodes[$last]['title'] .= "\n{$subCount} indirect nodes (niet getoond)";
        }
    }

    // Add any currently linked nodes not in ASL API
    foreach ($linkKeys as $id) {
        if (isset($seen[$id])) continue;
        $inTx  = in_array($id, $txKeys);
        $label = $resolveLabel($id);
        $nodes[] = ['id' => $id, 'label' => $label, 'group' => $inTx ? 'tx' : 'linked', 'title' => $label];
        $edges[] = ['from' => $id, 'to' => $MY_NODE, 'id' => "e_$id"];
        $seen[$id] = true;
    }

    echo json_encode([
        'nodes'     => $nodes,
        'edges'     => $edges,
        'tx_keys'   => $txKeys,
        'link_keys' => $linkKeys,
        'cached'    => file_exists($CACHE_FILE) ? date('H:i:s', filemtime($CACHE_FILE)) : null,
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
            // Node kan zijn losgekoppeld terwijl hij nog aan het uitzenden was
            if (isset($txState[$nodeKey])) {
                $lastTx[] = ['label' => $nodeLabel, 'started' => $txState[$nodeKey]['ts'], 'ended' => $ts];
                unset($txState[$nodeKey]);
            }
        }

        $text = trim(preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(\[.*?\])?\s*/', '', $line));
        $recent[] = ['ts' => $ts, 'type' => $type, 'text' => $text];
    }

    $recent = array_slice(array_reverse($recent), 0, 100);
    $lastTx = array_slice(array_reverse($lastTx), 0, 5);

    return [
        'data'          => ['updated' => date('H:i:s'), 'tx_active' => array_values($txState), 'last_tx' => $lastTx, 'linked' => array_values($linkState), 'recent' => $recent],
        'txState'       => $txState,
        'linkState'     => $linkState,
        'unlinkedState' => $unlinkedState,
        'lastTx'        => $lastTx,
        'recent'        => $recent,
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
    <title>AllStarLink Dashboard — <?= htmlspecialchars($MY_CALL) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vis-network@9.1.9/dist/vis-network.min.js"></script>
    <link href="https://unpkg.com/vis-network@9.1.9/dist/dist/vis-network.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .pulse { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
        #network-map { height: 500px; background: #111827; border-radius: 0.5rem; }
        #node-map { height: 400px; border-radius: 0.5rem; }
        .leaflet-popup-content-wrapper { background: #1f2937; color: #f3f4f6; border: 1px solid #374151; }
        .leaflet-popup-tip { background: #1f2937; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen font-mono text-sm">

<div class="max-w-7xl mx-auto px-4 py-6">

    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold text-white">AllStarLink Dashboard</h1>
            <p class="text-gray-400 text-xs mt-1">Node <?= htmlspecialchars($MY_NODE) ?> · <?= htmlspecialchars($MY_CALL) ?></p>
        </div>
        <div class="text-right">
            <div id="status-dot" class="inline-block w-3 h-3 rounded-full bg-green-400 pulse mr-1"></div>
            <span class="text-gray-400 text-xs">Bijgewerkt: <span id="updated">--:--:--</span></span>
        </div>
    </div>

    <!-- 2-koloms grid: TX + Verbonden -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">

        <!-- TX Active -->
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <h2 class="text-green-400 font-bold mb-3 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-400 pulse inline-block"></span>
                Nu aan het uitzenden
            </h2>
            <div id="tx-active" class="space-y-2 min-h-[3rem]">
                <p class="text-gray-500 italic">Niemand actief</p>
            </div>
            <div id="last-tx-wrap" class="mt-3 hidden">
                <p class="text-gray-500 text-xs mb-1">Laatste sessies:</p>
                <div id="last-tx" class="space-y-1"></div>
            </div>
        </div>

        <!-- Linked nodes -->
        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <h2 class="text-blue-400 font-bold mb-3">Verbonden nodes</h2>
            <div id="linked-nodes" class="space-y-2 min-h-[3rem]">
                <p class="text-gray-500 italic">Geen verbonden nodes</p>
            </div>
        </div>
    </div>

    <!-- Netwerkkaart: volledige breedte -->
    <div class="bg-gray-800 rounded-xl p-4 border border-gray-700 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-purple-400 font-bold">Netwerk</h2>
            <div class="flex gap-3 text-xs text-gray-400">
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-cyan-400 inline-block"></span>Eigen node</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span>Aan het uitzenden</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-blue-400 inline-block"></span>Verbonden</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-gray-500 inline-block"></span>Indirect / bekend</span>
            </div>
        </div>
        <div id="network-map" class="border border-gray-700 rounded-lg"></div>
        <p class="text-gray-600 text-xs mt-2 text-center">Klik op een node voor details · scrollen om in/uit te zoomen</p>
    </div>

    <!-- Kaart -->
    <div class="bg-gray-800 rounded-xl p-4 border border-gray-700 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-green-400 font-bold">Locatiekaart</h2>
            <div class="flex gap-3 text-xs text-gray-400">
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-cyan-400"></span>Eigen node</span>
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-green-400"></span>Aan het uitzenden</span>
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-blue-400"></span>Verbonden</span>
                <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-gray-500"></span>Inactief/onbekend</span>
            </div>
        </div>
        <div id="node-map" class="border border-gray-700"></div>
    </div>

    <!-- Recent activity -->
    <div class="bg-gray-800 rounded-xl border border-gray-700">
        <div class="px-4 py-3 border-b border-gray-700 flex items-center justify-between">
            <h2 class="font-bold">Recente activiteit</h2>
            <span class="text-gray-500 text-xs">laatste 100 regels</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-gray-400 border-b border-gray-700">
                        <th class="px-4 py-2 text-left w-36">Tijd</th>
                        <th class="px-4 py-2 text-left w-24">Type</th>
                        <th class="px-4 py-2 text-left">Bericht</th>
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
// ── Colours & groups ─────────────────────────────────────────────────────────
const TYPE_COLORS = {
    'TX-AAN': 'bg-green-600 text-white',
    'TX-UIT': 'bg-gray-600 text-white',
    'LINK':   'bg-blue-600 text-white',
    'UNLINK': 'bg-orange-500 text-white',
    'INFO':   'bg-sky-600 text-white',
    'WARN':   'bg-yellow-500 text-gray-900',
    'MSG':    'bg-gray-700 text-gray-300',
};

const VIS_GROUPS = {
    self:     { color: { background: '#06b6d4', border: '#0891b2' }, font: { color: '#fff', size: 14, bold: true }, size: 32 },
    tx:       { color: { background: '#22c55e', border: '#16a34a' }, font: { color: '#fff', size: 13, bold: true }, size: 24 },
    linked:   { color: { background: '#3b82f6', border: '#2563eb' }, font: { color: '#fff', size: 12 }, size: 20 },
    known:    { color: { background: '#4b5563', border: '#374151' }, font: { color: '#d1d5db', size: 11 }, size: 16 },
    indirect: { color: { background: '#374151', border: '#1f2937' }, font: { color: '#9ca3af', size: 10 }, size: 13 },
    inactive: { color: { background: '#1f2937', border: '#111827' }, font: { color: '#6b7280', size: 10 }, size: 13 },
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function nodeCard(node) {
    return `<div class="flex items-center gap-2 bg-gray-700/50 rounded px-3 py-2">
        <span class="font-bold text-white">${escHtml(node.label)}</span>
        <span class="text-gray-400 text-xs ml-auto">${node.ts.split(' ')[1]}</span>
    </div>`;
}


// ── Activity view ─────────────────────────────────────────────────────────────
let lastLinkKeys = [];

function applyData(data) {
    document.getElementById('updated').textContent = data.updated || '--';

    // Herlaad netwerk als verbonden nodes zijn gewijzigd
    const newLinkKeys = (data.link_keys || []).map(String).sort().join(',');
    const oldLinkKeys = lastLinkKeys.sort().join(',');
    if (visNodes && newLinkKeys !== oldLinkKeys) {
        loadNetworkMap();
    }
    lastLinkKeys = data.link_keys || [];

    const txEl = document.getElementById('tx-active');
    txEl.innerHTML = (data.tx_active && data.tx_active.length)
        ? data.tx_active.map(nodeCard).join('')
        : '<p class="text-gray-500 italic">Niemand actief</p>';

    const lastWrap = document.getElementById('last-tx-wrap');
    if (data.last_tx && data.last_tx.length) {
        lastWrap.classList.remove('hidden');
        document.getElementById('last-tx').innerHTML = data.last_tx.map(e =>
            `<div class="flex items-center gap-2 bg-gray-700/30 rounded px-3 py-1 text-xs">
                <span class="text-gray-200">${escHtml(e.label)}</span>
                <span class="text-gray-500 ml-auto">${e.started.split(' ')[1]} – ${e.ended.split(' ')[1]}</span>
            </div>`
        ).join('');
    } else {
        lastWrap.classList.add('hidden');
    }

    const lnEl = document.getElementById('linked-nodes');
    lnEl.innerHTML = (data.linked && data.linked.length)
        ? data.linked.map(nodeCard).join('')
        : '<p class="text-gray-500 italic">Geen verbonden nodes</p>';

    if (data.recent && data.recent.length) {
        document.getElementById('activity-log').innerHTML = data.recent.map(e => {
            const rowClass = e.type === 'TX-AAN'  ? 'bg-green-900/20'  :
                             e.type === 'LINK'    ? 'bg-blue-900/20'   :
                             e.type === 'UNLINK'  ? 'bg-orange-900/20' :
                             e.type === 'WARN'    ? 'bg-yellow-900/20' : '';
            const tc = TYPE_COLORS[e.type] || TYPE_COLORS['MSG'];
            return `<tr class="${rowClass}">
                <td class="px-4 py-1.5 text-gray-400 whitespace-nowrap">${escHtml(e.ts)}</td>
                <td class="px-4 py-1.5"><span class="px-2 py-0.5 rounded text-xs font-bold ${tc}">${escHtml(e.type)}</span></td>
                <td class="px-4 py-1.5 text-gray-200">${escHtml(e.text)}</td>
            </tr>`;
        }).join('');
    }

    document.getElementById('status-dot').classList.replace('bg-red-400', 'bg-green-400');

    // Update network map node colours + kaartmarkers
    if (visNodes) {
        updateNetworkStatus(data.tx_keys || [], data.link_keys || []);
        updateMapStatus(data.tx_keys || [], data.link_keys || [], lastMapNodes);
    }
}

// ── Network map ───────────────────────────────────────────────────────────────
let visNodes = null, visEdges = null, visNet = null;

async function loadNetworkMap() {
    networkLoaded = true;
    const container = document.getElementById('network-map');
    container.innerHTML = '<p class="text-gray-500 text-center pt-10">Netwerk laden...</p>';

    try {
        const res  = await fetch('?netmap=1');
        const data = await res.json();


        const nodeItems = data.nodes.map(n => ({
            id:    n.id,
            label: n.label,
            title: n.title || n.id,
            group: n.group,
            shape: 'ellipse',
            ...VIS_GROUPS[n.group] || VIS_GROUPS.known,
        }));

        const edgeItems = data.edges.map(e => ({
            id:     e.id,
            from:   e.from,
            to:     e.to,
            arrows: 'to',
            color:  { color: '#4b5563', highlight: '#9ca3af' },
            width:  n_group(e.from, data.nodes) === 'tx' ? 2 : 1,
        }));

        container.innerHTML = '';
        visNodes = new vis.DataSet(nodeItems);
        visEdges = new vis.DataSet(edgeItems);
        initMap(data.nodes);

        visNet = new vis.Network(container, { nodes: visNodes, edges: visEdges }, {
            physics: {
                solver: 'forceAtlas2Based',
                forceAtlas2Based: { gravitationalConstant: -60, springLength: 120 },
                stabilization: { iterations: 150 },
            },
            interaction: { hover: true, tooltipDelay: 100 },
            layout:  { improvedLayout: true },
            groups:  VIS_GROUPS,
        });

    } catch (err) {
        container.innerHTML = '<p class="text-red-400 text-center pt-10">Fout bij laden netwerk: ' + err.message + '</p>';
    }
}

function n_group(id, nodes) {
    const n = nodes.find(x => x.id === id);
    return n ? n.group : 'known';
}

function updateNetworkStatus(txKeys, linkKeys) {
    if (!visNodes) return;
    const txSet   = new Set(txKeys.map(String));
    const linkSet = new Set(linkKeys.map(String));
    visNodes.forEach(node => {
        if (node.id === '<?= $MY_NODE ?>') return;
        const inTx   = txSet.has(String(node.id));
        const linked = linkSet.has(String(node.id));
        const group  = inTx ? 'tx' : (linked ? 'linked' : node.group);
        const props  = VIS_GROUPS[group] || VIS_GROUPS.known;
        visNodes.update({ id: node.id, color: props.color });
    });
}

// ── Locatiekaart ──────────────────────────────────────────────────────────────
let leafletMap = null;
let mapMarkers = {};
let mapFitted  = false;
let lastMapNodes = [];

const MAP_COLORS = {
    self:     '#06b6d4',
    tx:       '#22c55e',
    linked:   '#3b82f6',
    known:    '#6b7280',
    indirect: '#4b5563',
    inactive: '#374151',
};

function makeMarkerIcon(color) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="24" height="36">
        <path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24C24 5.4 18.6 0 12 0z"
              fill="${color}" stroke="#fff" stroke-width="1.5"/>
        <circle cx="12" cy="12" r="5" fill="#fff" fill-opacity="0.8"/>
    </svg>`;
    return L.divIcon({
        html: svg,
        className: '',
        iconSize: [24, 36],
        iconAnchor: [12, 36],
        popupAnchor: [0, -36],
    });
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

    // Verwijder oude markers
    Object.values(mapMarkers).forEach(m => m.remove());
    mapMarkers = {};

    const bounds = [];
    withCoords.forEach(n => {
        const color = MAP_COLORS[n.group] || MAP_COLORS.known;
        const marker = L.marker([n.lat, n.lon], { icon: makeMarkerIcon(color) })
            .bindPopup(`<b>${escHtml(n.label.replace('\n', ' · '))}</b><br>${escHtml(n.title || '')}`)
            .addTo(leafletMap);
        mapMarkers[n.id] = marker;
        bounds.push([n.lat, n.lon]);
    });

    if (bounds.length && !mapFitted) {
        leafletMap.fitBounds(bounds, { padding: [40, 40] });
        mapFitted = true;
    }
    lastMapNodes = nodes;
}

function updateMapStatus(txKeys, linkKeys, nodes) {
    if (!leafletMap) return;
    const txSet   = new Set(txKeys.map(String));
    const linkSet = new Set(linkKeys.map(String));
    nodes.forEach(n => {
        const marker = mapMarkers[n.id];
        if (!marker) return;
        const group = n.id === '<?= $MY_NODE ?>' ? 'self'
            : txSet.has(String(n.id))   ? 'tx'
            : linkSet.has(String(n.id)) ? 'linked'
            : n.group;
        marker.setIcon(makeMarkerIcon(MAP_COLORS[group] || MAP_COLORS.known));
    });
}

async function refreshMap() {
    try {
        const res  = await fetch('?netmap=1');
        const data = await res.json();
        initMap(data.nodes);
        updateMapStatus(data.tx_keys || [], data.link_keys || [], data.nodes);
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
loadNetworkMap();
setInterval(refreshMap, 60000);
</script>
</body>
</html>
