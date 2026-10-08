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
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/data.php';

/**
 * Завантажує та парсить дані з сайту kiroe.com.ua
 * @return array|null|false Графік | сторінка прочитана без графіка | помилка завантаження
 */
function fetchFromSite() {
    $html = fetchUrl(SITE_URL);
    
    if ($html === false) {
        return false;
    }
    
    $extractedText = null;
    $parsed = parseHTMLSchedule($html, $extractedText);
    
    if (function_exists('logSourceContent') && $extractedText !== null && $extractedText !== '') {
        logSourceContent('site', $extractedText, ['url' => SITE_URL]);
    }
    
    if (!$parsed) {
        // Missing/changed markup is not proof that schedules were not announced.
        return $extractedText === null ? false : parseNoScheduleNotice($extractedText);
    }
    
    // ГАВ визначаємо тією ж логікою, що й для Telegram (parser.php)
    $parsed['emergency_mode'] = detectEmergencyMode($extractedText ?? '');
    return $parsed;
}

/**
 * Завантажує URL через curl або file_get_contents
 * @param string $url URL для завантаження
 * @return string|false HTML або false
 */
function fetchUrl($url) {
    return fetchUpstreamHtml($url);
}

/**
 * Перевіряє чи є повідомлення про ГАВ в HTML (обгортка над detectEmergencyMode з parser.php)
 * @param string $html HTML код сторінки
 * @return bool
 */
function checkEmergencyModeInHTML($html) {
    return (detectEmergencyMode($html) === true);
}
?>
