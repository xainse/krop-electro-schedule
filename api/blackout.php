<?php
/**
 * API endpoint для отримання графіку відключень електрики з kiroe.com.ua
 * 
 * Використання: blackout.php?queue=2.2
 * Повертає JSON з графіком відключень для вказаної черги
 * 
 * Використовує кешування на 10 хвилин для зменшення навантаження на джерело
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
const CACHE_TTL = 600; // 10 хвилин в секундах

/**
 * Повертає шлях до файлу кешу
 */
function getCachePath() {
    $cacheDir = __DIR__ . '/cache';
    // Створюємо папку cache якщо її немає
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    return $cacheDir . '/blackout_cache.json';
}

/**
 * Перевіряє чи кеш актуальний
 * @param string $cacheFile Шлях до файлу кешу
 * @param int $ttl Час життя кешу в секундах
 * @return bool
 */
function isCacheValid($cacheFile, $ttl = CACHE_TTL) {
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $cacheData = @json_decode(file_get_contents($cacheFile), true);
    if (!$cacheData || !isset($cacheData['timestamp'])) {
        return false;
    }
    
    $age = time() - $cacheData['timestamp'];
    return $age < $ttl;
}

/**
 * Завантажує дані з кешу
 * @param string $cacheFile Шлях до файлу кешу
 * @return array|false Масив з даними або false при помилці
 */
function loadCache($cacheFile) {
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $content = @file_get_contents($cacheFile);
    if ($content === false) {
        return false;
    }
    
    $data = @json_decode($content, true);
    return $data !== null ? $data : false;
}

/**
 * Зберігає дані в кеш
 * @param string $cacheFile Шлях до файлу кешу
 * @param array $data Дані для збереження
 * @return bool Успіх операції
 */
function saveCache($cacheFile, $data) {
    $cacheDir = dirname($cacheFile);
    
    // Створюємо папку якщо її немає
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            return false;
        }
    }
    
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents($cacheFile, $json);
    
    // Встановлюємо права доступу для файлу
    if ($result !== false) {
        @chmod($cacheFile, 0644);
    }
    
    return $result !== false;
}

/**
 * Нормалізує графік відключень
 * @param string $schedule Сирий графік
 * @return string Нормалізований графік
 */
function normalizeSchedule($schedule) {
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
    return trim($schedule);
}

/**
 * Парсить всі черги з HTML тексту
 * @param string $text Текст з графіками черг
 * @return array Асоціативний масив ['1.1' => 'schedule', '1.2' => 'schedule', ...]
 */
function parseAllQueues($text) {
    $queues = [];
    
    // Шукаємо всі рядки формату "Черга X.X: діапазони"
    // Використовуємо regex для знаходження всіх черг
    $pattern = '/Черга\s+(\d+\.\d+)\s*:\s*(.+?)(?=\n\s*Черга\s+\d+\.\d+|$)/is';
    
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $queueNum = $match[1];
            $schedule = normalizeSchedule($match[2]);
            $queues[$queueNum] = $schedule;
        }
    }
    
    return $queues;
}

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

// Отримуємо шлях до файлу кешу
$cacheFile = getCachePath();

// Перевіряємо чи кеш актуальний
$cacheData = null;
if (isCacheValid($cacheFile)) {
    $cacheData = loadCache($cacheFile);
}

// Якщо кеш не актуальний або відсутній - завантажуємо дані з сайту
if ($cacheData === false || !isset($cacheData['queues'])) {
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
    
    // Парсимо всі черги з HTML
    $allQueues = parseAllQueues($text);
    
    // Зберігаємо дані в кеш
    $cacheData = [
        'timestamp' => time(),
        'queues' => $allQueues
    ];
    
    // Спробуємо зберегти кеш, але не зупиняємо роботу якщо не вдалося
    saveCache($cacheFile, $cacheData);
}

// Отримуємо графік для запитуваної черги
$schedule = '';
if (isset($cacheData['queues'][$queue])) {
    $schedule = $cacheData['queues'][$queue];
}

// Повертаємо результат
echo json_encode([
    'success' => true,
    'queue' => $queue,
    'schedule' => $schedule,
    'updated' => isset($cacheData['timestamp']) ? $cacheData['timestamp'] : null,
    'source' => $sourceUrl
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>

