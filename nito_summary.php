<?php
// Réponse JSON + cache court
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=5, stale-while-revalidate=30');
header('Access-Control-Allow-Origin: *');

// Constante : 1 bloc = 60 secondes (demande explicite)
const BLOCK_SEC = 60;

// Horodatage serveur (ms)
$now_ms = (int) round(microtime(true) * 1000);

// Dossier d’état (fallback si l’API upstream tombe)
$cacheDir = __DIR__ . '/cache';
$stateFn  = $cacheDir . '/state.json';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }

// ---- 1) Appel unique à l’API résumé ----
$sum = null;
$ch = curl_init('https://nito-explorer.nitopool.fr/ext/getsummary');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_USERAGENT      => 'Nito-Halving-Server/1.2',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err === '' && $code === 200) {
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['blockcount'])) { $sum = $j; }
}

// Fallback si besoin : relire le dernier état valide
$lastState = [];
if (is_file($stateFn)) {
    $raw = @file_get_contents($stateFn);
    if ($raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) { $lastState = $tmp; }
    }
}
if (!$sum && isset($lastState['lastSummary'])) {
    $sum = $lastState['lastSummary'];
}
if (!$sum) {
    http_response_code(502);
    echo json_encode(['serverTime'=>$now_ms,'error'=>'upstream_unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- 2) Extraction/normalisation ----
$block      = (int)   ($sum['blockcount'] ?? 0);
$difficulty = (float) ($sum['difficulty'] ?? 0.0);
$supply     = (float) ($sum['supply']     ?? 0.0);

// Hashrate de l’API est en PH/s (string). On convertit correctement en TH/s/PH/s humain.
$rawPH = is_numeric($sum['hashrate'] ?? null) ? (float) $sum['hashrate'] : 0.0;
if ($rawPH >= 1.0) {
    // PH/s
    $hashUnit  = 'PH/s';
    $hashVal   = round($rawPH, 2);
    $hashHuman = rtrim(rtrim(number_format($hashVal, 2, '.', ' '), '0'), '.') . ' PH/s';
} else {
    // TH/s
    $th        = $rawPH * 1000.0;
    $hashUnit  = 'TH/s';
    $hashVal   = round($th, 1);
    $hashHuman = rtrim(rtrim(number_format($hashVal, 1, '.', ' '), '0'), '.') . ' TH/s';
}

// ---- 3) Halvings + récompenses ----
$halvings = [525600, 1051200, 1576800, 5256000, 10512000, 26280000, 105120000];

$nextHalving = $halvings[count($halvings)-1];
foreach ($halvings as $hb) { if ($hb > $block) { $nextHalving = $hb; break; } }

function current_reward(int $h): int {
    if     ($h <  525600)  return 512;
    elseif ($h < 1051200)  return 256;
    elseif ($h < 1576800)  return 128;
    elseif ($h < 5256000)  return 64;
    elseif ($h < 10512000) return 32;
    elseif ($h < 26280000) return 8;
    elseif ($h < 105120000)return 2;
    else                    return 0;
}
function next_reward(int $h): int {
    if     ($h <  525600)  return 256;
    elseif ($h < 1051200)  return 128;
    elseif ($h < 1576800)  return 64;
    elseif ($h < 5256000)  return 32;
    elseif ($h < 10512000) return 8;
    elseif ($h < 26280000) return 2;
    elseif ($h < 105120000)return 0;
    else                    return 0;
}

$curReward  = current_reward($block);
$nxtReward  = next_reward($block);

// ---- 4) Calcul ETA FIXE : 60 s par bloc, fige entre deux blocs ----
$blocksRemaining = max(0, $nextHalving - $block);

// L’ETA ne dépend QUE du nombre de blocs restants et de 60 s/bloc.
// On fige la cible entre deux rafraîchissements côté client :
// targetHalvingTs = now + blocksRemaining * 60 s
$targetHalvingTs = (int) ($now_ms + $blocksRemaining * BLOCK_SEC * 1000);

// Progression du cycle courant (pour la barre)
$prevHalving = 0;
foreach ($halvings as $hb) { if ($hb <= $block) $prevHalving = $hb; else break; }
$totalSpan = max(1, $nextHalving - $prevHalving);
$doneSpan  = max(0, $block - $prevHalving);
$progressPct = max(0.0, min(100.0, ($doneSpan / $totalSpan) * 100.0));

// ---- 5) Sauvegarde d’un mini-état pour fallback ----
$save = [
    'lastSummary' => [
        'blockcount' => $block,
        'difficulty' => $difficulty,
        'supply'     => $supply,
        'hashrate'   => (string)$rawPH
    ],
];
@file_put_contents($stateFn, json_encode($save, JSON_UNESCAPED_SLASHES), LOCK_EX);

// ---- 6) Réponse JSON (supply/difficulty sans décimales) ----
echo json_encode([
    'serverTime'       => $now_ms,
    'as_of_ms'         => $now_ms,
    'block'            => $block,
    'supply'           => (int) round($supply),
    'difficulty'       => (int) round($difficulty),
    'hashrate'         => [
        'unit'  => $hashUnit,
        'human' => $hashHuman,
        'value' => $hashVal,
        'rawPH' => round($rawPH, 4)
    ],
    'blockTimeSec'     => BLOCK_SEC,            // toujours 60
    'currentReward'    => $curReward,
    'nextHalvingBlock' => $nextHalving,
    'nextReward'       => $nxtReward,
    'blocksRemaining'  => $blocksRemaining,
    'progressPct'      => $progressPct,
    'targetHalvingTs'  => $targetHalvingTs
], JSON_UNESCAPED_SLASHES);
