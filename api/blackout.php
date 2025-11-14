<?php
/**
 * API endpoint для отримання графіку відключень електрики з kiroe.com.ua
 * 
 * Використання: blackout.php?queue=2.2
 * Повертає JSON з графіком відключень для вказаної черги
 */

header('Content-Type: application/json; charset=utf-8');

// Отримуємо параметр queue
$queue = isset($_GET['queue']) ? trim($_GET['queue']) : '';

// Валідація параметра queue (формат X.X)
if (!preg_match('/^\d+\.\d+$/', $queue)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid queue parameter. Expected format: X.X (e.g., 2.2)'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$sourceUrl = 'https://kiroe.com.ua/electricity-blackout';

// Завантажуємо HTML сторінку через curl (більш надійно для зовнішніх URL)
// Сумісно з PHP 7.4
function fetchUrl($url) {
    // Спочатку пробуємо через curl
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch === false) {
            // curl_init не вдався
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ]);
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Автоматична декомпресія
            
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($html !== false && $httpCode == 200 && strlen($html) > 0) {
                return $html;
            }
        }
    }
    
    // Якщо curl не доступний або не спрацював, пробуємо file_get_contents
    // Перевіряємо чи дозволено allow_url_fopen
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: uk-UA,uk;q=0.9'
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

$html = fetchUrl($sourceUrl);

// Перевірка чи вдалося завантажити сторінку
if ($html === false) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Page not available',
        'queue' => $queue,
        'source' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Створюємо DOMDocument для парсингу HTML
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();

// Знаходимо елемент з ID info_popup
$xpath = new DOMXPath($dom);
$infoPopup = $xpath->query("//*[@id='info_popup']")->item(0);

if (!$infoPopup) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Info popup element not found',
        'queue' => $queue,
        'source' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Знаходимо елемент з класом fancybox_body_desc
$bodyDesc = $xpath->query(".//*[contains(@class, 'fancybox_body_desc')]", $infoPopup)->item(0);

if (!$bodyDesc) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Schedule content not found',
        'queue' => $queue,
        'source' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Отримуємо текстовий вміст
$text = $bodyDesc->textContent;

// Парсимо рядки з графіками
// Шукаємо рядок формату "Черга X.X: діапазони"
// Графік може бути на кількох рядках, тому шукаємо до наступного "Черга" або до кінця
$pattern = '/Черга\s+' . preg_quote($queue, '/') . '\s*:\s*(.+?)(?=\n\s*Черга\s+\d+\.\d+|$)/is';
if (preg_match($pattern, $text, $matches)) {
    // Знайдено графік для черги
    $schedule = trim($matches[1]);
    
    // Нормалізуємо пробіли (замінюємо множинні пробіли/переноси на один пробіл)
    $schedule = preg_replace('/[\s\n\r]+/', ' ', $schedule);
    
    // Видаляємо зайві пробіли навколо ком та дефісів
    $schedule = preg_replace('/\s*,\s*/', ', ', $schedule);
    $schedule = preg_replace('/\s*-\s*/', '-', $schedule);
    
    // Видаляємо пробіли навколо двокрапки в часі (10:00-11:30)
    $schedule = preg_replace('/\s*:\s*/', ':', $schedule);
    
    // Нормалізуємо час: додаємо :00 до годин без хвилин
    // Замінюємо формат "HH-HH" на "HH:00-HH:00"
    // Наприклад: "06-08" → "06:00-08:00", "10:00-11:30" залишається як є
    $schedule = preg_replace_callback('/(\d{1,2})(?::(\d{2}))?\-(\d{1,2})(?::(\d{2}))?/', function($matches) {
        $startHour = $matches[1];
        $startMin = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : '00';
        $endHour = $matches[3];
        $endMin = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : '00';
        
        // Якщо хвилини не вказані, встановлюємо 00
        // Якщо хвилини вказані, залишаємо як є
        return sprintf('%02d:%s-%02d:%s', $startHour, $startMin, $endHour, $endMin);
    }, $schedule);
    
    // Фінальна очистка пробілів
    $schedule = trim($schedule);
    
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'schedule' => $schedule,
        'updated' => null,
        'source' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    // Черга не знайдена - повертаємо success з порожнім schedule
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'schedule' => '',
        'updated' => null,
        'source' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>

