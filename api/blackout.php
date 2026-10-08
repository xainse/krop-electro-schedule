<?php
/** Public GET endpoint; runtime files are private and all upstream work is serialized. */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function jsonFlags($pretty = false) {
    $flags = JSON_UNESCAPED_UNICODE;
    if ($pretty) $flags |= JSON_PRETTY_PRINT;
    // PHP < 7.2 has no JSON_INVALID_UTF8_SUBSTITUTE; bare constant name becomes a string and breaks `|`.
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    return $flags;
}
function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, jsonFlags(true));
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    respond(['success' => false, 'error' => 'Method not allowed'], 405);
}
foreach (['queue', 'all', 'force_refresh', 'test_emergency'] as $parameter) {
    if (isset($_GET[$parameter]) && !is_string($_GET[$parameter])) {
        respond(['success' => false, 'error' => 'Parameters must be strings'], 400);
    }
}
if (isset($_GET['all']) && !in_array($_GET['all'], ['0', '1'], true)) {
    respond(['success' => false, 'error' => 'all must be 0 or 1'], 400);
}
$requestAll = ($_GET['all'] ?? '') === '1';
$queue = trim($_GET['queue'] ?? '');
if ((!$requestAll || $queue !== '') && !preg_match('/^[1-6]\.[12]$/D', $queue)) {
    respond(['success' => false, 'error' => 'Invalid queue. Expected 1.1 through 6.2'], 400);
}

require_once __DIR__ . '/telegram_fetcher.php';
require_once __DIR__ . '/site_fetcher.php';
require_once __DIR__ . '/response.php';
$start = microtime(true);
if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
// Defense in depth if the host disables rewrite rules in the API directory.
foreach ([CACHE_DIR, LOGS_DIR] as $directory) {
    if (!is_dir($directory)) @mkdir($directory, 0755, true);
    if (!is_file($directory . '/.htaccess')) @file_put_contents($directory . '/.htaccess', "Require all denied\n");
}
$cachePath = CACHE_DIR . '/blackout_cache.json';
function readBlackoutCache($path) {
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    return is_array($data) ? $data : null;
}
function writeBlackoutCache($path, $data) {
    $json = json_encode($data, jsonFlags());
    $tmp = tempnam(dirname($path), 'blackout-');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
$cache = readBlackoutCache($cachePath);
$view = scheduleResponse($cache);
if ($view['stale']) {
    $lock = @fopen(CACHE_DIR . '/refresh.lock', 'c');
    // Parallel requests can immediately use the old snapshot without multiplying upstream traffic.
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        try {
            $cache = readBlackoutCache($cachePath);
            $lastCheckPath = CACHE_DIR . '/last_source_check.txt';
            $lastCheck = (int)@file_get_contents($lastCheckPath);
            if (scheduleResponse($cache)['stale'] && time() - $lastCheck >= TELEGRAM_CHECK_INTERVAL) {
                // Failed requests are throttled too. Public force_refresh cannot bypass this.
                @file_put_contents($lastCheckPath, (string)time(), LOCK_EX);
                $fresh = fetchFromTelegram(20);
                if (!$fresh || empty($fresh['queues']) || dateToKey($fresh['date'] ?? null) !== date('Y-m-d')) {
                    $fresh = fetchFromSite();
                    if ($fresh) $fresh['source'] = SITE_URL;
                } else {
                    $fresh['source'] = TELEGRAM_CHANNEL_URL;
                }
                if ($fresh && !empty($fresh['queues']) && dateToKey($fresh['date'] ?? null) === date('Y-m-d')) {
                    $fresh['verified_at'] = time();
                    $cache = $fresh;
                    writeBlackoutCache($cachePath, $cache);
                }
            }
        } catch (Throwable $error) {
            error_log('Blackout refresh failed: ' . $error->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } elseif ($lock) fclose($lock);
}
$response = scheduleResponse($cache, $requestAll ? null : $queue);
logApiRequest([
    'queue' => $requestAll ? 'all' : $queue,
    'source' => $response['source'] ?? 'unavailable',
    'success' => $response['available'],
    'response_time_ms' => round((microtime(true) - $start) * 1000, 2),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
]);
cleanOldLogs();
respond($response, $cache === null && !$response['available'] ? 503 : 200);
