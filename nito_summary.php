<?php
/**
 * Nito Halving/Network state endpoint
 * - CORS + JSON
 * - 5s cache
 * - Pulls chain data from Explorer (robust endpoints)
 * - Computes next halving with CUSTOM schedule (not simple /2 each time)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ------------------------------
// Config
// ------------------------------
$CACHE_TTL_SEC      = 5;
$CACHE_DIR          = __DIR__ . '/cache';
$CACHE_FILE         = $CACHE_DIR . '/state.json';

$EXPLORER_HOST      = 'https://nito-explorer.nitopool.fr';
$API_GETBLOCKCOUNT  = $EXPLORER_HOST . '/api/getblockcount';
$API_GETDIFFICULTY  = $EXPLORER_HOST . '/api/getdifficulty';
$API_GETHASHPS      = $EXPLORER_HOST . '/api/getnetworkhashps';
$EXT_GETSUPPLY      = $EXPLORER_HOST . '/ext/getmoneysupply';
$EXT_GETSUMMARY     = $EXPLORER_HOST . '/ext/getsummary'; // fallback only

$BLOCK_TIME_SEC     = 60;   // target block time
$START_REWARD       = 512;  // initial block subsidy

// Final reward behavior:
// - If SOFT_FORK_FINAL_REWARD is true, last step becomes 2 -> 1 Nito (instead of 2 -> 0)
$SOFT_FORK_FINAL_REWARD = false;
$FINAL_REWARD_OVERRIDE  = 1;

// ------------------------------
// Cache (serve fresh if younger than TTL)
// ------------------------------
$nowMs = (int) round(microtime(true) * 1000);
if (is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE) < $CACHE_TTL_SEC)) {
  $raw = file_get_contents($CACHE_FILE);
  if ($raw !== false) {
    echo $raw;
    exit;
  }
}

// ------------------------------
// HTTP helpers
// ------------------------------
function http_get($url, $timeout = 5) {
  $ctx = stream_context_create([
    'http' => [
      'timeout' => $timeout,
      'ignore_errors' => true,
      'header' => "Cache-Control: no-cache\r\n"
    ]
  ]);
  return @file_get_contents($url, false, $ctx);
}
function http_get_json($url, $timeout = 5) {
  $raw = http_get($url, $timeout);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  if (!is_array($j)) return null;
  return $j;
}
function to_number($val) {
  // Accept JSON numbers or strings like "769.0 TH/s" (strip non-numeric except . and exponent)
  if (is_numeric($val)) return (float)$val;
  if (!is_string($val)) return 0.0;
  // common cases: "769.0 TH/s", "1,234,567", "7.69e+14"
  $clean = trim($val);
  // If it contains letters like H/s, remove everything after first space
  $parts = preg_split('/\s+/', $clean);
  $first = $parts[0];
  // Remove thousand separators
  $first = str_replace([',', ' '], '', $first);
  // If still not numeric, last resort: regex extract number
  if (!is_numeric($first)) {
    if (preg_match('/[-+]?[0-9]*\.?[0-9]+([eE][-+]?[0-9]+)?/', $clean, $m)) {
      $first = $m[0];
    }
  }
  return is_numeric($first) ? (float)$first : 0.0;
}

// ------------------------------
// Fetch primitives from robust endpoints
// ------------------------------
$height_raw = http_get($API_GETBLOCKCOUNT);
$height     = $height_raw !== false ? (int)trim($height_raw) : 0;

$difficulty_raw = http_get($API_GETDIFFICULTY);
$difficulty     = $difficulty_raw !== false ? (float)trim($difficulty_raw) : 0.0;

$hashps_raw = http_get($API_GETHASHPS);
$hashps     = $hashps_raw !== false ? (float)trim($hashps_raw) : 0.0; // H/s as number

$supply_raw = http_get($EXT_GETSUPPLY);
$supply     = $supply_raw !== false ? (float)trim($supply_raw) : 0.0;

// Fallback to /ext/getsummary if any of the above are missing
if ($height <= 0 || $hashps <= 0 || $difficulty <= 0 || $supply <= 0) {
  $sum = http_get_json($EXT_GETSUMMARY);
  if (is_array($sum)) {
    if ($height <= 0) {
      // Try common keys: height, blockcount, blocks
      if (isset($sum['height']))      $height = (int)$sum['height'];
      if (isset($sum['blockcount']))  $height = (int)$sum['blockcount'];
      if (isset($sum['blocks']))      $height = (int)$sum['blocks'];
    }
    if ($difficulty <= 0 && isset($sum['difficulty']))
      $difficulty = to_number($sum['difficulty']);
    if ($supply <= 0 && isset($sum['supply']))
      $supply = to_number($sum['supply']);
    if ($hashps <= 0 && isset($sum['hashrate'])) {
      // If the summary returns a human string like "769.0 TH/s", convert to H/s
      $hr = $sum['hashrate'];
      if (is_numeric($hr)) {
        $hashps = (float)$hr;
      } else if (is_string($hr)) {
        // Parse "value unit"
        if (preg_match('/([-+]?[0-9]*\.?[0-9]+)\s*([kMGTPEZY]?H)\/s/i', $hr, $m)) {
          $v = (float)$m[1];
          $u = strtoupper($m[2]); // kH, MH, GH, TH, PH...
          $scale = [
            'H'  => 0,
            'KH' => 3, 'MH' => 6, 'GH' => 9, 'TH' => 12, 'PH' => 15,
            'EH' => 18, 'ZH' => 21, 'YH' => 24
          ];
          $exp = isset($scale[$u]) ? $scale[$u] : 0;
          $hashps = $v * pow(10, $exp);
        } else {
          // last resort: numeric part, assume H/s
          $hashps = to_number($hr);
        }
      }
    }
  }
}

// ------------------------------
// Humanize hashrate helper
// ------------------------------
function human_hashrate($h) {
  $units = ['H/s','kH/s','MH/s','GH/s','TH/s','PH/s','EH/s','ZH/s','YH/s'];
  $idx = 0;
  while ($h >= 1000 && $idx < count($units)-1) { $h /= 1000; $idx++; }
  return [
    'value' => $h,
    'unit'  => $units[$idx],
    'human' => sprintf('%.3f %s', $h, $units[$idx])
  ];
}

// ------------------------------
// Custom halving schedule
// ------------------------------
$halvings = [
  ['name' => 'First halving',   'block' =>   530000,   'to' => 256],
  ['name' => 'Second halving',  'block' =>  1042400,   'to' => 128],
  ['name' => 'Third halving',   'block' =>  1576800,   'to' =>  64],
  ['name' => 'Fourth halving',  'block' =>  5256000,   'to' =>  32],
  ['name' => 'Fifth halving',   'block' => 10512000,   'to' =>  16],
  ['name' => 'Sixth halving',   'block' => 26280000,   'to' =>   2],
  ['name' => 'Seventh halving', 'block' => 105120000,  'to' =>   0], // overridden to 1 if soft fork
];
if ($SOFT_FORK_FINAL_REWARD) {
  $halvings[count($halvings)-1]['to'] = max(0, (int)$FINAL_REWARD_OVERRIDE);
}

// ------------------------------
// Determine next halving and rewards
// ------------------------------
$nextIdx = null;
for ($i = 0; $i < count($halvings); $i++) {
  if ($height < (int)$halvings[$i]['block']) { $nextIdx = $i; break; }
}

if ($nextIdx === null) {
  // All halvings completed
  $currentReward     = (int)$halvings[count($halvings)-1]['to']; // 0 by default, or 1 if soft fork
  $nextReward        = 0;
  $nextHalvingBlock  = null;
  $blocksRemaining   = 0;
  $progressPct       = 100;
  $targetHalvingTs   = $nowMs;
} else {
  $nextHalving       = $halvings[$nextIdx];
  $nextHalvingBlock  = (int)$nextHalving['block'];
  $currentReward     = (int) ($nextIdx === 0 ? $START_REWARD : $halvings[$nextIdx - 1]['to']);
  $nextReward        = (int)$nextHalving['to'];
  $blocksRemaining   = max(0, $nextHalvingBlock - $height);

  // progress from previous halving boundary to next
  $prevBoundary      = ($nextIdx === 0) ? 0 : (int)$halvings[$nextIdx - 1]['block'];
  $interval          = max(1, $nextHalvingBlock - $prevBoundary);
  $done              = max(0, $height - $prevBoundary);
  $progressPct       = max(0, min(100, ($done / $interval) * 100));

  $targetHalvingTs   = $nowMs + ($blocksRemaining * $BLOCK_TIME_SEC * 1000);
}

// ------------------------------
// Build response
// ------------------------------
$out = [
  'serverTime'       => $nowMs,
  'as_of_ms'         => $nowMs,
  'block'            => (int)$height,
  'difficulty'       => $difficulty,
  'supply'           => $supply,
  'hashrate'         => human_hashrate($hashps),

  'currentReward'    => (int)$currentReward,
  'nextReward'       => (int)$nextReward,
  'nextHalvingBlock' => $nextHalvingBlock,
  'blocksRemaining'  => (int)$blocksRemaining,
  'progressPct'      => $progressPct,
  'targetHalvingTs'  => (int)$targetHalvingTs,
];

// ------------------------------
// Persist cache and serve
// ------------------------------
if (!is_dir($CACHE_DIR)) { @mkdir($CACHE_DIR, 0775, true); }

$tmp = json_encode($out, JSON_UNESCAPED_UNICODE);
if ($tmp !== false) {
  @file_put_contents($CACHE_FILE, $tmp, LOCK_EX);

  // stamp the as_of_ms with cache mtime for consistency on subsequent reads
  $mt = @filemtime($CACHE_FILE);
  if ($mt) {
    $out['as_of_ms'] = (int)($mt * 1000);
    $tmp = json_encode($out, JSON_UNESCAPED_UNICODE);
    if ($tmp !== false) { @file_put_contents($CACHE_FILE, $tmp, LOCK_EX); }
  }
}

echo @file_get_contents($CACHE_FILE) ?: $tmp;
?>
