<?php
/**
 * Site Fetcher
 * 
 * Парсить дані з kiroe.com.ua
 * Використовується як fallback якщо Telegram не доступний
 * 
 * Перенесена логіка з поточного blackout.php
 */

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser.php';

/**
 * Завантажує та парсить дані з сайту kiroe.com.ua
 * @return array|false Дані графіку або false
 */
function fetchFromSite() {
    $html = fetchUrl(SITE_URL);
    
    if ($html === false) {
        return false;
    }
    
    // Парсимо HTML
    $parsed = parseHTMLSchedule($html);
    
    if (!$parsed) {
        return false;
    }
    
    // Додаємо перевірку ГАВ з HTML
    $parsed['emergency_mode'] = checkEmergencyModeInHTML($html);
    
    return $parsed;
}

/**
 * Завантажує URL через curl або file_get_contents
 * @param string $url URL для завантаження
 * @return string|false HTML або false
 */
function fetchUrl($url) {
    // Спочатку пробуємо через curl
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_ENCODING, '');
            
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($html !== false && $httpCode == 200 && strlen($html) > 0) {
                return $html;
            }
        }
    }
    
    // Fallback на file_get_contents
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'timeout' => 15,
                'follow_location' => 1,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $html = @file_get_contents($url, false, $context);
        if ($html !== false && strlen($html) > 0) {
            return $html;
        }
    }
    
    return false;
}

/**
 * Перевіряє чи є повідомлення про ГАВ в HTML
 * @param string $html HTML код сторінки
 * @return bool
 */
function checkEmergencyModeInHTML($html) {
    // Спочатку перевіряємо чи ГАВ/СГАВ скасовано
    $cancellationPatterns = [
        '/дію\s+графіка\s+аварійних\s+відключень\s*\(?\s*ГАВ\s*\)?\s+скасовано/ui',
        '/скасовано\s+дію\s+графіка\s+аварійних\s+відключень/ui',
        '/ГАВ\s+скасовано/ui',
        '/скасовано\s+ГАВ/ui',
        '/дію\s+спеціального\s+графіка\s+аварійних\s+відключень\s*\(?\s*СГАВ\s*\)?\s+скасовано/ui',
        '/скасовано\s+дію\s+спеціального\s+графіка\s+аварійних\s+відключень/ui',
        '/СГАВ\s+скасовано/ui',
        '/скасовано\s+СГАВ/ui'
    ];
    
    foreach ($cancellationPatterns as $pattern) {
        if (preg_match($pattern, $html)) {
            return false;
        }
    }
    
    // Шукаємо активний ГАВ або СГАВ
    $activationPatterns = [
        '/графік\s+аварійних\s+відключень/ui',
        '/ГАВ/u',
        '/введено\s+в\s+дію\s+графік\s+аварійних/ui',
        '/спеціальний\s+графік\s+аварійних\s+відключень/ui',
        '/СГАВ/u',
        '/введено\s+в\s+дію\s+спеціальний\s+графік\s+аварійних/ui'
    ];
    
    foreach ($activationPatterns as $pattern) {
        if (preg_match($pattern, $html)) {
            return true;
        }
    }
    
    return false;
}
?>
