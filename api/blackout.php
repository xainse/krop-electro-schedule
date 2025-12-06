<?php
/**
 * API endpoint для отримання графіку відключень електрики з kiroe.com.ua
 * 
 * Використання: blackout.php?queue=2.2
 * Повертає JSON з графіком відключень для вказаної черги
 * 
 * Використовує кешування на 10 хвилин для зменшення навантаження на джерело
 */

// CORS заголовки для дозволу прямого виклику з браузера
// ВАЖЛИВО: ці заголовки мають бути встановлені ДО будь-якого виводу
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400'); // Кешування preflight запитів на 24 години
header('Content-Type: application/json; charset=utf-8');

// Обробка preflight запитів (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Фіксуємо час початку запиту для вимірювання швидкості відповіді
$startTime = microtime(true);

// Отримуємо параметр queue
$queue = isset($_GET['queue']) ? trim($_GET['queue']) : '';

// Валідація параметра queue (формат X.X)
if (!preg_match('/^\d+\.\d+$/', $queue)) {
    $responseTime = (microtime(true) - $startTime) * 1000; // в мілісекундах
    
    // Логуємо невалідний запит
    logRequest([
        'queue' => $queue,
        'source' => 'invalid',
        'response_time_ms' => round($responseTime, 2),
        'success' => false,
        'ip' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
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
 * Повертає шлях до файлу логу для поточної дати
 * @return string Шлях до файлу логу
 */
function getLogPath() {
    $logsDir = __DIR__ . '/logs';
    // Створюємо папку logs якщо її немає
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    $date = date('Y-m-d');
    return $logsDir . '/blackout_' . $date . '.log';
}

/**
 * Отримує IP адресу клієнта
 * @return string IP адреса
 */
function getClientIp() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
    
    // Спочатку шукаємо публічні IP в заголовках
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    // Якщо не знайдено публічний IP, повертаємо REMOTE_ADDR (може бути приватним)
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Логує запит до API
 * @param array $data Дані для логування
 * @return void
 */
function logRequest($data) {
    $logFile = getLogPath();
    
    // Форматуємо timestamp з мілісекундами
    $timestamp = date('Y-m-d H:i:s') . '.' . str_pad((int)(microtime(true) * 1000) % 1000, 3, '0', STR_PAD_LEFT);
    
    $logEntry = [
        'timestamp' => $timestamp,
        'queue' => $data['queue'] ?? '',
        'source' => $data['source'] ?? 'unknown',
        'response_time_ms' => $data['response_time_ms'] ?? 0,
        'success' => $data['success'] ?? false,
        'ip' => $data['ip'] ?? '',
        'user_agent' => $data['user_agent'] ?? ''
    ];
    
    $jsonLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
    
    // Спробуємо записати лог, але не зупиняємо роботу якщо не вдалося
    @file_put_contents($logFile, $jsonLine, FILE_APPEND | LOCK_EX);
    
    // Встановлюємо права доступу для файлу
    if (file_exists($logFile)) {
        @chmod($logFile, 0644);
    }
}

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
 * Перевіряє чи є повідомлення про графік аварійних відключень (ГАВ) в HTML
 * @param string $html HTML код сторінки
 * @return bool true якщо ГАВ активний, false інакше
 */
function checkEmergencyMode($html) {
    // Спочатку перевіряємо чи ГАВ скасовано
    // Якщо знайдено текст про скасування - повертаємо false одразу
    $cancellationPatterns = [
        '/дію\s+графіка\s+аварійних\s+відключень\s*\(?\s*ГАВ\s*\)?\s+скасовано/i',
        '/скасовано\s+дію\s+графіка\s+аварійних\s+відключень/i',
        '/ГАВ\s+скасовано/i',
        '/скасовано\s+ГАВ/i',
        '/графік\s+аварійних\s+відключень\s*\(?\s*ГАВ\s*\)?\s+скасовано/i'
    ];
    
    foreach ($cancellationPatterns as $pattern) {
        if (preg_match($pattern, $html)) {
            return false; // ГАВ скасовано
        }
    }
    
    // Шукаємо текст про "графік аварійних відключень" або "ГАВ"
    // Перевіряємо різні варіанти написання та комбінації
    $patterns = [
        // Точні збіги про ГАВ
        '/графік\s+аварійних\s+відключень/i',
        '/графік\s*аварійних\s*відключень/i',
        '/ГАВ/i',
        // Комбінації з "введено в дію"
        '/введено\s+в\s+дію\s+графік\s+аварійних/i',
        '/введено\s+в\s+дію\s+графік\s*аварійних/i',
        // Інші варіанти
        '/аварійних\s+відключень/i',
        '/аварійного\s+відключення/i',
        // Шукаємо також в тексті повідомлень про важливу інформацію
        '/Увага.*аварійних/i',
        '/Увага.*ГАВ/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html)) {
            return true;
        }
    }
    
    // Додаткова перевірка: шукаємо комбінацію "графік" + "аварій" в межах 50 символів
    if (preg_match('/графік.{0,50}аварій|аварій.{0,50}графік/i', $html)) {
        return true;
    }
    
    return false;
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

// Відстежуємо джерело даних (cache або site)
$dataSource = 'cache';

// Завжди завантажуємо кеш якщо він існує (незалежно від TTL)
// Це гарантує, що дані будуть доступні навіть якщо нові дані не вдалося отримати
$cacheData = null;
if (file_exists($cacheFile)) {
    $cacheData = loadCache($cacheFile);
}

// Якщо передано параметр force_refresh=1, примусово оновлюємо дані з сайту
$forceRefresh = isset($_GET['force_refresh']) && $_GET['force_refresh'] == '1';

// Прапорець для відстеження чи вдалося отримати нові дані
$fetchFailed = false;
$newDataObtained = false;

// Перевіряємо чи потрібно оновлювати дані
// Оновлюємо якщо: кеш відсутній, кеш застарілий, або force_refresh
$needUpdate = false;
if ($forceRefresh) {
    $needUpdate = true;
} elseif ($cacheData === false || !isset($cacheData['queues'])) {
    $needUpdate = true;
} elseif (!isCacheValid($cacheFile)) {
    // Кеш застарілий - намагаємося отримати нові дані, але залишаємо кеш як fallback
    $needUpdate = true;
}

// Якщо потрібно оновити - спробуємо завантажити дані з сайту
if ($needUpdate) {
    $dataSource = 'site';
    $html = fetchUrl($sourceUrl);
    
    // Перевірка чи вдалося завантажити сторінку
    // Якщо сайт недоступний (kiroe.com.ua не працює) - використовуємо кеш якщо він є
    if ($html === false) {
        $fetchFailed = true;
        // Якщо є кеш - використаємо його, не повертаємо помилку
        // Це гарантує, що фронтенд завжди отримає дані (навіть застарілі) якщо кеш існує
        if ($cacheData !== null && isset($cacheData['queues'])) {
            $dataSource = 'cache';
            // Продовжуємо виконання з даними з кешу
        } else {
            // Кешу немає і дані не отримано - повертаємо помилку
            $responseTime = (microtime(true) - $startTime) * 1000; // в мілісекундах
            
            // Логуємо помилку
            logRequest([
                'queue' => $queue,
                'source' => $dataSource,
                'response_time_ms' => round($responseTime, 2),
                'success' => false,
                'ip' => getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Page not available',
                'queue' => $queue,
                'source' => $sourceUrl
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    } else {
        // HTML отримано, продовжуємо парсинг
        // Створюємо DOMDocument для парсингу HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        // Знаходимо елемент з ID info_popup
        $xpath = new DOMXPath($dom);
        $infoPopup = $xpath->query("//*[@id='info_popup']")->item(0);
        
        if (!$infoPopup) {
            $fetchFailed = true;
            // Якщо є кеш - використаємо його
            if ($cacheData !== null && isset($cacheData['queues'])) {
                $dataSource = 'cache';
                // Продовжуємо виконання з даними з кешу
            } else {
                // Кешу немає - повертаємо помилку
                $responseTime = (microtime(true) - $startTime) * 1000; // в мілісекундах
                
                // Логуємо помилку
                logRequest([
                    'queue' => $queue,
                    'source' => $dataSource,
                    'response_time_ms' => round($responseTime, 2),
                    'success' => false,
                    'ip' => getClientIp(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Info popup element not found',
                    'queue' => $queue,
                    'source' => $sourceUrl
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        } else {
            // Знаходимо елемент з класом fancybox_body_desc
            $bodyDesc = $xpath->query(".//*[contains(@class, 'fancybox_body_desc')]", $infoPopup)->item(0);
            
            if (!$bodyDesc) {
                $fetchFailed = true;
                // Якщо є кеш - використаємо його
                if ($cacheData !== null && isset($cacheData['queues'])) {
                    $dataSource = 'cache';
                    // Продовжуємо виконання з даними з кешу
                } else {
                    // Кешу немає - повертаємо помилку
                    $responseTime = (microtime(true) - $startTime) * 1000; // в мілісекундах
                    
                    // Логуємо помилку
                    logRequest([
                        'queue' => $queue,
                        'source' => $dataSource,
                        'response_time_ms' => round($responseTime, 2),
                        'success' => false,
                        'ip' => getClientIp(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Schedule content not found',
                        'queue' => $queue,
                        'source' => $sourceUrl
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    exit;
                }
            } else {
                // Всі перевірки пройдено, парсимо дані
                // Отримуємо текстовий вміст
                $text = $bodyDesc->textContent;
                
                // Перевіряємо чи є повідомлення про ГАВ в HTML
                // Шукаємо в усьому HTML, а не тільки в bodyDesc, бо повідомлення може бути в іншому місці
                $emergencyMode = checkEmergencyMode($html);
                
                // Парсимо всі черги з HTML
                $allQueues = parseAllQueues($text);
                
                // Якщо ГАВ активний, але графіки не оновлені (порожній або менше черг) - використовуємо графіки з кешу
                if ($emergencyMode && (empty($allQueues) || count($allQueues) < count($cacheData['queues'] ?? []))) {
                    // Використовуємо графіки з кешу, але залишаємо новий emergency_mode
                    if ($cacheData !== null && isset($cacheData['queues']) && !empty($cacheData['queues'])) {
                        $allQueues = $cacheData['queues'];
                    }
                }
                
                // Зберігаємо дані в кеш
                $cacheData = [
                    'timestamp' => time(),
                    'queues' => $allQueues,
                    'emergency_mode' => $emergencyMode
                ];
                
                // Спробуємо зберегти кеш, але не зупиняємо роботу якщо не вдалося
                saveCache($cacheFile, $cacheData);
                $newDataObtained = true;
            }
        }
    }
}

// Отримуємо графік для запитуваної черги
$schedule = '';
if (isset($cacheData['queues'][$queue])) {
    $schedule = $cacheData['queues'][$queue];
}

// Отримуємо стан ГАВ з кешу (за замовчуванням false якщо не встановлено)
$emergencyMode = isset($cacheData['emergency_mode']) ? (bool)$cacheData['emergency_mode'] : false;

// Тестовий режим: якщо передано параметр test_emergency=1, встановлюємо emergency_mode = true для тестування
// Це дозволяє протестувати відображення повідомлення про ГАВ на фронтенді
if (isset($_GET['test_emergency']) && $_GET['test_emergency'] == '1') {
    $emergencyMode = true;
}

// Вимірюємо час виконання запиту
$responseTime = (microtime(true) - $startTime) * 1000; // в мілісекундах

// Логуємо успішний запит
logRequest([
    'queue' => $queue,
    'source' => $dataSource,
    'response_time_ms' => round($responseTime, 2),
    'success' => true,
    'ip' => getClientIp(),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

// Повертаємо результат
echo json_encode([
    'success' => true,
    'queue' => $queue,
    'schedule' => $schedule,
    'emergency_mode' => $emergencyMode,
    'updated' => isset($cacheData['timestamp']) ? $cacheData['timestamp'] : null,
    'source' => $sourceUrl
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>

