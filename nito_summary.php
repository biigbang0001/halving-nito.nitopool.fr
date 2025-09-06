<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/state.json';
$ttl       = 5;                
$nowMs     = (int) round(microtime(true) * 1000);


if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    $raw = file_get_contents($cacheFile);
    if ($raw !== false) { echo $raw; exit; }
}

$src  = 'https://nito-explorer.nitopool.fr/ext/getsummary';
$ctx  = stream_context_create(['http' => ['timeout' => 6]]);
$json = @file_get_contents($src, false, $ctx);
if ($json === false) {
    if (is_file($cacheFile)) { readfile($cacheFile); exit; }
    http_response_code(502);
    echo json_encode(['error' => 'upstream_unreachable']);
    exit;
}
$d = json_decode($json, true);

$block        = isset($d['blockcount']) ? (int)$d['blockcount'] : 0;
$difficulty   = isset($d['difficulty']) ? (float)$d['difficulty'] : 0.0;
$supply       = isset($d['supply']) ? (float)$d['supply'] : 0.0;

$rawPH        = isset($d['hashrate']) ? (float)$d['hashrate'] : 0.0;
$th           = $rawPH * 1000.0;
$humanHash    = $th >= 1000 ? sprintf('%.1f PH/s', $th/1000) : sprintf('%.1f TH/s', $th);


$startReward  = 512;
$halvings = [
    ['name' => 'First halving',  'block' =>  525600,   'to' => 256],
    ['name' => 'Second halving', 'block' => 1051200,   'to' => 128],
    ['name' => 'Third halving',  'block' => 1576800,   'to' =>  64],
    ['name' => 'Fourth halving', 'block' => 5256000,   'to' =>  32],
    ['name' => 'Fifth halving',  'block' => 10512000,  'to' =>   8],
    ['name' => 'Sixth halving',  'block' => 26280000,  'to' =>   2],
    ['name' => 'Seventh halving','block' => 105120000, 'to' =>   0], 
    

$nextIdx = null;
for ($i = 0; $i < count($halvings); $i++) {
    if ($block < $halvings[$i]['block']) { $nextIdx = $i; break; }
}

if ($nextIdx === null) {
    $currentReward     = 0;
    $nextReward        = 0;
    $nextHalvingBlock  = null;
    $blocksRemaining   = 0;
    $halvingLabel      = 'All halvings completed';
    $targetHalvingTs   = $nowMs;
} else {
    $currentReward     = (int) max(0, $startReward >> $nextIdx);   
    $nextReward        = (int) max(0, $startReward >> ($nextIdx+1));
    $nextHalvingBlock  = (int) $halvings[$nextIdx]['block'];
    $blocksRemaining   = max(0, $nextHalvingBlock - $block);
    $halvingLabel      = $halvings[$nextIdx]['name'] . " ({$currentReward} → {$nextReward} Nito)";
    $blockTimeSec      = 60; 
    $targetHalvingTs   = $nowMs + ($blocksRemaining * $blockTimeSec * 1000);
}

$displayDifficulty = (int) round($difficulty);
$displaySupply     = (int) round($supply);

$out = [
    'serverTime'       => $nowMs,
    'as_of_ms'         => $nowMs,                 
    'block'            => $block,
    'supply'           => $supply,
    'supplyDisplay'    => $displaySupply,
    'difficulty'       => $difficulty,
    'difficultyDisplay'=> $displayDifficulty,
    'hashrate'         => ['rawPH' => $rawPH, 'unit' => 'TH/s', 'human' => $humanHash, 'value' => $th],
    'nextHalvingBlock' => $nextHalvingBlock,
    'currentReward'    => $currentReward,
    'nextReward'       => $nextReward,
    'blocksRemaining'  => $blocksRemaining,
    'halvingLabel'     => $halvingLabel,
    'targetHalvingTs'  => $targetHalvingTs
];

if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
$tmp = json_encode($out, JSON_UNESCAPED_UNICODE);
if ($tmp !== false) {
    @file_put_contents($cacheFile, $tmp, LOCK_EX);
    $mt = filemtime($cacheFile);
    if ($mt) {
        $out['as_of_ms'] = (int)($mt * 1000);
        $tmp = json_encode($out, JSON_UNESCAPED_UNICODE);
        if ($tmp !== false) { @file_put_contents($cacheFile, $tmp, LOCK_EX); }
    }
}

echo file_get_contents($cacheFile) ?: $tmp;
