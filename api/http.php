<?php
/** Shared upstream boundary. No insecure stream fallback: cURL is a deployment prerequisite. */
function upstreamUrlAllowed($url) {
    if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower($parts['host'] ?? ''), ['t.me', 'kiroe.com.ua', 'www.kiroe.com.ua'], true)
        && (!isset($parts['port']) || $parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
}
function publicUpstreamIPv4($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $n = ip2long($ip);
    // Also exclude CGNAT, documentation, benchmark and multicast/reserved space.
    foreach ([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',3]] as [$network,$bits]) {
        $mask = -1 << (32 - $bits);
        if (($n & $mask) === (ip2long($network) & $mask)) return false;
    }
    return true;
}
function upstreamRedirectUrl($current, $location) {
    if (!is_string($location) || $location === '') return false;
    if (strpos($location, '://') !== false) return $location;
    if (substr($location, 0, 2) === '//') return 'https:' . $location;
    $p = parse_url($current);
    $origin = 'https://' . $p['host'];
    if ($location[0] === '/') return $origin . $location;
    if ($location[0] === '?') return $origin . ($p['path'] ?? '/') . $location;
    return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $location;
}
function boundedCurlRequest($url, $ip, $timeoutMs, $maxBytes) {
    if (!function_exists('curl_init')) return false;
    $ch = curl_init($url);
    if ($ch === false) return false;
    $body = ''; $location = null; $headerBytes = 0;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        (defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS) => defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS,
        (defined('CURLOPT_REDIR_PROTOCOLS_STR') ? CURLOPT_REDIR_PROTOCOLS_STR : CURLOPT_REDIR_PROTOCOLS) => defined('CURLOPT_REDIR_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
        CURLOPT_CONNECTTIMEOUT_MS => min(3000, max(1, $timeoutMs)),
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [parse_url($url, PHP_URL_HOST) . ':443:' . $ip],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'KropSchedule/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
            $body .= $chunk; return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$headerBytes, &$location) {
            $headerBytes += strlen($line);
            if ($headerBytes > 32768) return 0;
            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
            return strlen($line);
        },
    ]);
    $ok = curl_exec($ch);
    if ($ok === false) return false;
    return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => $body, 'location' => $location];
}
function fetchUpstreamHtml($url, $transport = null, $resolver = null) {
    $transport = $transport ?? 'boundedCurlRequest';
    $resolver = $resolver ?? function($host) { return gethostbynamel($host) ?: []; };
    $deadline = microtime(true) + 4;
    $remainingBytes = 2097152;
    for ($hop = 0; $hop <= 3; $hop++) {
        if (!upstreamUrlAllowed($url)) return false;
        $ips = $resolver(parse_url($url, PHP_URL_HOST));
        if (!$ips) return false;
        foreach ($ips as $ip) if (!publicUpstreamIPv4($ip)) return false;
        $remainingMs = (int)(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) return false;
        $response = $transport($url, $ips[0], $remainingMs, $remainingBytes);
        if (!is_array($response) || !is_string($response['body'] ?? null) || microtime(true) > $deadline) return false;
        $remainingBytes -= strlen($response['body']);
        if ($remainingBytes < 0) return false;
        if (($response['status'] ?? 0) === 200) {
            $type = strtolower(trim(explode(';', $response['type'] ?? '')[0]));
            return in_array($type, ['text/html', 'application/xhtml+xml'], true) && $response['body'] !== '' ? $response['body'] : false;
        }
        if (!in_array($response['status'] ?? 0, [301,302,303,307,308], true)) return false;
        $url = upstreamRedirectUrl($url, $response['location'] ?? null);
    }
    return false;
}
