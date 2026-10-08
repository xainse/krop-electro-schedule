<?php
require_once __DIR__ . '/bootstrap.php';

/** One bounded file for all counters. Never trust a client-supplied forwarded address. */
function checkRequestLimit($ip, $now = null, $path = null, $perClient = 60, $global = 600) {
    $now = $now ?? time();
    $path = $path ?? CACHE_DIR . '/request_limits.json';
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
    $handle = @fopen($path, 'c+');
    if (!$handle) return 1;
    @chmod($path, 0600);
    if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return 1; }
    try {
        $window = (int)floor($now / 60);
        $raw = stream_get_contents($handle, 262145);
        $state = strlen($raw) <= 262144 ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['window'] ?? null) !== $window) {
            $state = ['window' => $window, 'salt' => bin2hex(random_bytes(16)), 'total' => 0, 'clients' => []];
        }
        if (!is_array($state['clients'] ?? null) || !is_string($state['salt'] ?? null)) return 1;
        $key = hash_hmac('sha256', (string)$ip, $state['salt']);
        if (($state['total'] ?? 0) >= $global || ($state['clients'][$key] ?? 0) >= $perClient) return 60 - ($now % 60);
        $state['total']++;
        $state['clients'][$key] = ($state['clients'][$key] ?? 0) + 1;
        $json = json_encode($state);
        if ($json === false || strlen($json) > 262144) return 1;
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) return 1;
        return 0;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

/** Skip excess records; retention alone does not bound today's log. */
function appendBoundedLog($path, $entry, $perFile = 2097152, $totalLimit = 20971520) {
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) return false;
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    if ($line === false || strlen($line) > min(110000, $perFile - 1)) return false;
    $line .= "\n";
    $lock = @fopen($directory . '/.write.lock', 'c');
    if (!$lock) return false;
    @chmod($directory . '/.write.lock', 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
    try {
        clearstatcache();
        $size = is_file($path) ? filesize($path) : 0;
        if ($size + strlen($line) > $perFile) return false;
        $files = glob($directory . '/*.log') ?: [];
        foreach ($files as $i => $file) {
            if ($file !== $path && filemtime($file) < time() - 30 * 86400 && @unlink($file)) unset($files[$i]);
        }
        $files = array_values($files);
        usort($files, function($a, $b) { return filemtime($a) <=> filemtime($b); });
        $total = array_sum(array_map('filesize', $files));
        foreach ($files as $file) {
            if ($total + strlen($line) <= $totalLimit) break;
            if ($file === $path) continue;
            $bytes = filesize($file);
            if (@unlink($file)) $total -= $bytes;
        }
        if ($total + strlen($line) > $totalLimit) return false;
        $ok = @file_put_contents($path, $line, FILE_APPEND) === strlen($line);
        @chmod($path, 0600);
        return $ok;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
