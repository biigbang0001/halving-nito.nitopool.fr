<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=5, stale-while-revalidate=30');
header('Access-Control-Allow-Origin: *');

$now_ms   = (int) round(microtime(true) * 1000);
$cacheDir = __DIR__ . '/cache';
$stateFn  = $cacheDir . '/state.json';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$state = [];
if (is_file($stateFn)) {
    $raw = @file_get_contents($stateFn);
    if ($raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $state = $tmp;
    }
}

$srcUrl = 'https://nito-explorer.nitopool.fr/ext/getsummary';
$ch     = curl_init($srcUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_USERAGENT      => 'Nito-Halving-Server/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$sum = null;
if ($err === '' && $code === 200) {
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['blockcount'])) {
        $sum = $j;
    }
}

if (!$sum && isset($state['lastSummary']) && is_array($state['lastSummary'])) {
    $sum = $state['lastSummary'];
}

if (!$sum) {
    http_response_code(502);
    echo json_encode([
        'serverTime' => $now_ms,
        'error'      => 'upstream_unavailable'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$block      = (int) ($sum['blockcount'] ?? 0);
$difficulty = (float) ($sum['difficulty'] ?? 0);
$supply     = (float) ($sum['supply'] ?? 0);

$rawPH = is_numeric($sum['hashrate'] ?? null) ? (float) $sum['hashrate'] : 0.0;
$humanHash = '-';
if ($rawPH > 0) {
    if ($rawPH >= 1.0) {
        $humanHash = rtrim(rtrim(number_format($rawPH, 2, '.', ' '), '0'), '.') . ' PH/s';
    } else {
        $th = $rawPH * 1000.0;
        if ($th >= 1.0) {
            $humanHash = rtrim(rtrim(number_format($th, 1, '.', ' '), '0'), '.') . ' TH/s';
        } else {
            $gh = $th * 1000.0;
            $humanHash = rtrim(rtrim(number_format($gh, 1, '.', ' '), '0'), '.') . ' GH/s';
        }
    }
}

$halvings = [525600, 1051200, 1576800, 5256000, 10512000, 26280000, 105120000];

function next_halving_block(int $h, array $hs): int {
    foreach ($hs as $b) if ($b > $h) return $b;
    return end($hs);
}
function current_reward(int $h, array $hs): int {
    if ($h < $hs[0]) return 512;
    for ($i = 0; $i < count($hs); $i++) {
        if ($h < $hs[$i]) {
            return [512,256,128,64,32,8,2][$i];
        }
    }
    return 0;
}
function next_reward(int $h, array $hs): int {
    if ($h < $hs[0]) return 256;
    for ($i = 0; $i < count($hs); $i++) {
        if ($h < $hs[$i]) {
            return [256,128,64,32,8,2,0][$i];
        }
    }
    return 0;
}

$nextHalving = next_halving_block($block, $halvings);
$curReward   = current_reward($block, $halvings);
$nextReward  = next_reward($block, $halvings);

$lastBlock     = (int)    ($state['lastBlock']        ?? 0);
$lastSeenMs    = (int)    ($state['lastSeenBlockMs']  ?? 0);
$avgBlockSec   = (float)  ($state['blockTimeSec']     ?? 60.0);

if ($block > 0 && $block > $lastBlock && $lastSeenMs > 0) {
    $deltaBlocks = max(1, $block - $lastBlock);
    $deltaSecObs = max(1.0, ($now_ms - $lastSeenMs) / 1000.0);
    $obsPerBlock = $deltaSecObs / $deltaBlocks;

    $obsPerBlock = max(15.0, min(600.0, $obsPerBlock));
    if ($avgBlockSec > 0) {
        $avgBlockSec = 0.75 * $avgBlockSec + 0.25 * $obsPerBlock;
    } else {
        $avgBlockSec = $obsPerBlock;
    }
} elseif ($avgBlockSec <= 0) {
    $avgBlockSec = 60.0;
}

$avgBlockSec = max(15.0, min(600.0, $avgBlockSec));

$blocksRemaining = max(0, $nextHalving - $block);

if ($blocksRemaining > 0) {
    $eta_ms = (int) round($now_ms + $blocksRemaining * $avgBlockSec * 1000.0);
    if ($eta_ms <= $now_ms) $eta_ms = $now_ms + 1000;
} else {
    $eta_ms = $now_ms;
}

$prevHalving = 0;
foreach ($halvings as $hb) { if ($hb <= $block) $prevHalving = $hb; else break; }
$totalSpan   = max(1, $nextHalving - $prevHalving);
$doneSpan    = max(0, $block - $prevHalving);
$progressPct = max(0.0, min(100.0, ($doneSpan / $totalSpan) * 100.0));

$state['lastSummary']     = [
    'blockcount' => $block,
    'difficulty' => $difficulty,
    'supply'     => $supply,
    'hashrate'   => (string) $rawPH
];
$state['lastBlock']       = $block;
$state['lastSeenBlockMs'] = $now_ms;
$state['blockTimeSec']    = $avgBlockSec;

@file_put_contents($stateFn, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);

$out = [
    'serverTime'       => $now_ms,
    'as_of_ms'         => $now_ms,

    'block'            => $block,
    'supply'           => round($supply),       
    'difficulty'       => round($difficulty),   

    'hashrate'         => [
        'rawPH' => round($rawPH, 4),
        'unit'  => (strpos($humanHash, 'PH/s') !== false ? 'PH/s' : (strpos($humanHash, 'TH/s') !== false ? 'TH/s' : 'GH/s')),
        'human' => $humanHash,
        'value' => (strpos($humanHash, 'TH/s') !== false ? round($rawPH*1000.0, 1) : (strpos($humanHash, 'PH/s') !== false ? round($rawPH, 2) : round($rawPH*1000.0*1000.0, 1)))
    ],

    'blockTimeSec'     => round($avgBlockSec, 3),

    'currentReward'    => $curReward,
    'nextHalvingBlock' => $nextHalving,
    'nextReward'       => $nextReward,

    'blocksRemaining'  => $blocksRemaining,
    'progressPct'      => $progressPct,

    'targetHalvingTs'  => $eta_ms
];

echo json_encode($out, JSON_UNESCAPED_SLASHES);
