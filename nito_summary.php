<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/cache/nito_summary.json';
$ttl = 5;

function get_json(string $url, int $timeout = 8): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 200 || $out === false) throw new RuntimeException($err ?: ('HTTP '.$code));
    $j = json_decode($out, true);
    if (!is_array($j)) throw new RuntimeException('Invalid upstream JSON');
    return $j;
}

function next_halving_block(int $h): int {
    foreach ([525600,1051200,1576800,5256000,10512000,26280000,105120000] as $b) if ($b > $h) return $b;
    return 105120000;
}
function prev_halving_block(int $h): int {
    $p = 0;
    foreach ([0,525600,1051200,1576800,5256000,10512000,26280000,105120000] as $b){ if ($b <= $h) $p = $b; else break; }
    return $p;
}
function reward_at(int $h): int {
    $sched = [0=>512,525600=>256,1051200=>128,1576800=>64,5256000=>32,10512000=>8,26280000=>2,105120000=>0];
    $r = 512; foreach ($sched as $bh=>$rr){ if ($h < $bh) break; $r = $rr; } return $r;
}

$nowMs = (int) floor(microtime(true) * 1000);

$useCache = false;
if (is_file($cacheFile)) {
    $ageMs = $nowMs - (int) (filemtime($cacheFile) * 1000);
    if ($ageMs < $ttl * 1000) $useCache = true;
}
if ($useCache) { readfile($cacheFile); exit; }

$prev = null;
if (is_file($cacheFile)) {
    $rawPrev = file_get_contents($cacheFile);
    $prev = $rawPrev ? json_decode($rawPrev, true) : null;
}

try {
    $sum = get_json('https://nito-explorer.nitopool.fr/ext/getsummary', 8);

    $height     = (int)   ($sum['blockcount'] ?? 0);
    $supply     = (float) ($sum['supply']     ?? 0);
    $difficulty = (float) ($sum['difficulty'] ?? 0);

    $rawPH = (float)($sum['hashrate'] ?? 0.0); // PH/s
    $scaledVal = ($rawPH >= 1.0) ? round($rawPH, 3) : round($rawPH * 1000.0, 1);
    $scaledUnit = ($rawPH >= 1.0) ? 'PH/s' : 'TH/s';
    $hashHuman = $scaledVal.' '.$scaledUnit;

    $blockTimeSec = 60.0;
    if (is_array($prev)) {
        $prevHeight = (int)($prev['block'] ?? 0);
        $prevAsOf   = (int)($prev['as_of_ms'] ?? 0);
        $prevBT     = (float)($prev['blockTimeSec'] ?? 60.0);
        if ($prevAsOf > 0) {
            $dt = max(1, ($nowMs - $prevAsOf) / 1000.0);
            $db = max(0, $height - $prevHeight);
            if ($db > 0) {
                $inst = $dt / $db;
                $blockTimeSec = 0.7 * $prevBT + 0.3 * $inst;
            } else {
                $blockTimeSec = $prevBT;
            }
        }
    }
    if ($blockTimeSec < 20 || $blockTimeSec > 120) $blockTimeSec = 60.0;

    $nextHalving = next_halving_block($height);
    $prevHalving = prev_halving_block($height);
    $currReward  = reward_at($height);
    $nextReward  = reward_at($nextHalving);

    $blocksRemaining = max(0, $nextHalving - $height);
    $remainingSec = (int) round($blocksRemaining * $blockTimeSec);
    $targetHalvingTs = $nowMs + ($remainingSec * 1000);

    $totalSpan = max(1, $nextHalving - $prevHalving);
    $doneSpan  = max(0, $height - $prevHalving);
    $progressPct = max(0.0, min(100.0, 100.0 * $doneSpan / $totalSpan));

    $out = [
        'serverTime'       => $nowMs,
        'as_of_ms'         => $nowMs,
        'anchor_ms'        => $nowMs,
        'remaining_sec'    => $remainingSec,
        'block'            => $height,
        'supply'           => $supply,
        'difficulty'       => $difficulty,
        'hashrate'         => [
            'rawPH'       => $rawPH,
            'scaledValue' => $scaledVal,
            'scaledUnit'  => $scaledUnit,
            'human'       => $hashHuman
        ],
        'blockTimeSec'     => round($blockTimeSec, 5),
        'currentReward'    => $currReward,
        'nextHalvingBlock' => $nextHalving,
        'nextReward'       => $nextReward,
        'blocksRemaining'  => $blocksRemaining,
        'progressPct'      => $progressPct,
        'targetHalvingTs'  => $targetHalvingTs
    ];

    $json = json_encode($out, JSON_UNESCAPED_SLASHES);
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0775, true);
    $tmp = $cacheFile.'.tmp';
    file_put_contents($tmp, $json);
    @chmod($tmp, 0664);
    @rename($tmp, $cacheFile);

    header('Cache-Control: public, max-age=5, stale-while-revalidate=30');
    echo $json;
} catch (Throwable $e) {
    if (is_file($cacheFile)) {
        header('Cache-Control: public, max-age=5, stale-while-revalidate=30');
        readfile($cacheFile);
        exit;
    }
    http_response_code(502);
    echo json_encode(['error'=>'upstream_unavailable','message'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
