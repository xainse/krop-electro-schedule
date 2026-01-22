<?php
/**
 * API endpoint для отримання графіку відключень (НОВА ВЕРСІЯ з Telegram інтеграцією)
 * 
 * Каскадний fallback:
 * 1. Спочатку читає з JSON кешу (schedules.json)
 * 2. Якщо кеш порожній/застарілий → пробує Telegram
 * 3. Якщо Telegram не вдався → пробує сайт kiroe.com.ua
 * 4. Якщо нічого не вдалося → повертає помилку або старі дані
 * 
 * Використання: blackout_new.php?queue=2.2 або ?all=1
 */

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Обробка preflight запитів
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/telegram_fetcher.php';
require_once __DIR__ . '/site_fetcher.php';

// Фіксуємо час початку
$startTime = microtime(true);

// Отримуємо параметри
$requestAll = isset($_GET['all']) && $_GET['all'] == '1';
$queue = isset($_GET['queue']) ? trim($_GET['queue']) : '';

// Валідація параметра queue (якщо не all)
if (!$requestAll) {
    if (!preg_match('/^\d+\.\d+$/', $queue)) {
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        logApiRequest([
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
}

// Змінна для відстеження джерела даних
$dataSource = 'cache';

// 1. КРОК 1: Читаємо з JSON кешу
$data = getSchedules($requestAll ? null : $queue);

if ($data && isDataFresh(DATA_TTL)) {
    // Дані є і свіжі → повертаємо одразу
    $dataSource = 'cache';
} else {
    // Дані застарілі або відсутні → пробуємо оновити
    
    // 2. КРОК 2a: Спочатку через Telegram
    try {
        $telegramData = fetchFromTelegram(20);
        if ($telegramData && !empty($telegramData['queues'])) {
            // Telegram успішний → зберігаємо та перечитуємо
            saveSchedules(
                $telegramData['queues'],
                $telegramData['date'],
                $telegramData['emergency_mode'],
                SOURCE_TELEGRAM,
                ''
            );
            $data = getSchedules($requestAll ? null : $queue);
            $dataSource = SOURCE_TELEGRAM;
        }
    } catch (Exception $e) {
        error_log('Telegram fetch failed: ' . $e->getMessage());
    }
    
    // 3. КРОК 2b: Якщо Telegram не вдався → пробуємо сайт
    if (!$data || empty($data['queues'])) {
        try {
            $siteData = fetchFromSite();
            if ($siteData && !empty($siteData['queues'])) {
                saveSchedules(
                    $siteData['queues'],
                    $siteData['date'],
                    $siteData['emergency_mode'],
                    SOURCE_SITE
                );
                $data = getSchedules($requestAll ? null : $queue);
                $dataSource = SOURCE_SITE;
            }
        } catch (Exception $e) {
            error_log('Site fetch failed: ' . $e->getMessage());
        }
    }
    
    // 4. КРОК 3: Якщо нічого не вдалося
    if (!$data || empty($data['queues'])) {
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        logApiRequest([
            'queue' => $requestAll ? 'all' : $queue,
            'source' => 'none',
            'response_time_ms' => round($responseTime, 2),
            'success' => false,
            'ip' => getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error' => 'No data available from any source'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// Формуємо відповідь
$responseTime = (microtime(true) - $startTime) * 1000;

if ($requestAll) {
    // Повертаємо всі черги
    logApiRequest([
        'queue' => 'all',
        'source' => $dataSource,
        'response_time_ms' => round($responseTime, 2),
        'success' => true,
        'ip' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'queues' => $data['queues'] ?? [],
        'emergency_mode' => $data['emergency_mode'] ?? false,
        'updated' => $data['timestamp'] ?? null,
        'source' => $dataSource
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    // Повертаємо конкретну чергу
    logApiRequest([
        'queue' => $queue,
        'source' => $dataSource,
        'response_time_ms' => round($responseTime, 2),
        'success' => true,
        'ip' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'schedule' => $data['schedule'] ?? '',
        'emergency_mode' => $data['emergency_mode'] ?? false,
        'updated' => $data['timestamp'] ?? null,
        'source' => $dataSource
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
/**
 * API endpoint для отримання графіку відключень електрики
 * 
 * Використання: 
 * - blackout.php?queue=2.2   - отримати графік конкретної черги
 * - blackout.php?all=1       - отримати всі черги
 * 
 * Джерела даних (cascade fallback):
 * 1. JSON кеш (schedules.json) - найшвидше
 * 2. Telegram (через telegram_fetcher.php) - якщо кеш застарілий/порожній
 * 3. Сайт kiroe.com.ua (через site_fetcher.php) - якщо Telegram не вдався
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Обробка preflight запитів
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Фіксуємо час початку
$startTime = microtime(true);

/**
 * Повертає JSON відповідь та завершує скрипт
 */
function respondJSON($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Отримує IP адресу клієнта
 */
function getClientIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
               'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER)) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// ========================================
// Валідація параметрів
// ========================================

$requestAll = isset($_GET['all']) && $_GET['all'] == '1';
$queue = isset($_GET['queue']) ? trim($_GET['queue']) : '';

// Якщо запитується конкретна черга - валідуємо формат
if (!$requestAll && !preg_match('/^\d+\.\d+$/', $queue)) {
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    logApiRequest([
        'queue' => $queue,
        'source' => 'invalid',
        'response_time_ms' => round($responseTime, 2),
        'success' => false,
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    respondJSON([
        'success' => false,
        'error' => 'Invalid queue parameter. Expected format: X.X (e.g., 2.2)'
    ], 400);
}

// ========================================
// 1. Спроба читання з JSON кешу
// ========================================

$dataSource = 'cache';
$data = null;

if ($requestAll) {
    $data = getSchedules(); // Всі черги
} else {
    $data = getSchedules($queue); // Конкретна черга
}

// Перевіряємо чи дані свіжі
if ($data !== null && isDataFresh(DATA_TTL)) {
    // Дані є і свіжі - повертаємо
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    logApiRequest([
        'queue' => $requestAll ? 'all' : $queue,
        'source' => $dataSource,
        'response_time_ms' => round($responseTime, 2),
        'success' => true,
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    if ($requestAll) {
        respondJSON([
            'success' => true,
            'queues' => $data['queues'] ?? [],
            'emergency_mode' => $data['emergency_mode'] ?? false,
            'updated' => $data['timestamp'] ?? null,
            'date' => $data['date'] ?? null,
            'source' => $data['source'] ?? SOURCE_TELEGRAM
        ]);
    } else {
        respondJSON([
            'success' => true,
            'queue' => $queue,
            'schedule' => $data['schedule'] ?? '',
            'emergency_mode' => $data['emergency_mode'] ?? false,
            'updated' => $data['timestamp'] ?? null,
            'date' => $data['date'] ?? null,
            'source' => $data['source'] ?? SOURCE_TELEGRAM
        ]);
    }
}

// ========================================
// 2. Дані застарілі або відсутні - пробуємо оновити через Telegram
// ========================================

$dataSource = SOURCE_TELEGRAM;

try {
    require_once __DIR__ . '/telegram_fetcher.php';
    
    $telegramData = fetchFromTelegram(20);
    
    if ($telegramData !== null && !empty($telegramData['queues'])) {
        // Дані отримано - зберігаємо
        $saved = saveSchedules(
            $telegramData['queues'],
            $telegramData['date'],
            $telegramData['emergency_mode'],
            SOURCE_TELEGRAM,
            $telegramData['raw_message'] ?? ''
        );
        
        if ($saved) {
            // Успішно збережено - читаємо знову та повертаємо
            if ($requestAll) {
                $data = getSchedules();
            } else {
                $data = getSchedules($queue);
            }
            
            if ($data !== null) {
                $responseTime = (microtime(true) - $startTime) * 1000;
                
                logApiRequest([
                    'queue' => $requestAll ? 'all' : $queue,
                    'source' => $dataSource,
                    'response_time_ms' => round($responseTime, 2),
                    'success' => true,
                    'ip' => getClientIP(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                if ($requestAll) {
                    respondJSON([
                        'success' => true,
                        'queues' => $data['queues'] ?? [],
                        'emergency_mode' => $data['emergency_mode'] ?? false,
                        'updated' => $data['timestamp'] ?? null,
                        'date' => $data['date'] ?? null,
                        'source' => SOURCE_TELEGRAM
                    ]);
                } else {
                    respondJSON([
                        'success' => true,
                        'queue' => $queue,
                        'schedule' => $data['schedule'] ?? '',
                        'emergency_mode' => $data['emergency_mode'] ?? false,
                        'updated' => $data['timestamp'] ?? null,
                        'date' => $data['date'] ?? null,
                        'source' => SOURCE_TELEGRAM
                    ]);
                }
            }
        }
    }
} catch (Exception $e) {
    // Логуємо помилку але продовжуємо (спробуємо сайт)
    error_log('Telegram fetch failed: ' . $e->getMessage());
}

// ========================================
// 3. Telegram не вдався - пробуємо сайт
// ========================================

$dataSource = SOURCE_SITE;

try {
    require_once __DIR__ . '/site_fetcher.php';
    
    $siteData = fetchFromSite();
    
    if ($siteData !== null && !empty($siteData['queues'])) {
        // Дані отримано - зберігаємо
        $saved = saveSchedules(
            $siteData['queues'],
            $siteData['date'],
            $siteData['emergency_mode'],
            SOURCE_SITE
        );
        
        if ($saved) {
            // Успішно збережено - читаємо знову та повертаємо
            if ($requestAll) {
                $data = getSchedules();
            } else {
                $data = getSchedules($queue);
            }
            
            if ($data !== null) {
                $responseTime = (microtime(true) - $startTime) * 1000;
                
                logApiRequest([
                    'queue' => $requestAll ? 'all' : $queue,
                    'source' => $dataSource,
                    'response_time_ms' => round($responseTime, 2),
                    'success' => true,
                    'ip' => getClientIP(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                if ($requestAll) {
                    respondJSON([
                        'success' => true,
                        'queues' => $data['queues'] ?? [],
                        'emergency_mode' => $data['emergency_mode'] ?? false,
                        'updated' => $data['timestamp'] ?? null,
                        'date' => $data['date'] ?? null,
                        'source' => SOURCE_SITE
                    ]);
                } else {
                    respondJSON([
                        'success' => true,
                        'queue' => $queue,
                        'schedule' => $data['schedule'] ?? '',
                        'emergency_mode' => $data['emergency_mode'] ?? false,
                        'updated' => $data['timestamp'] ?? null,
                        'date' => $data['date'] ?? null,
                        'source' => SOURCE_SITE
                    ]);
                }
            }
        }
    }
} catch (Exception $e) {
    // Логуємо помилку
    error_log('Site fetch failed: ' . $e->getMessage());
}

// ========================================
// 4. Всі джерела не вдалися
// ========================================

// Якщо є застарілі дані - повертаємо їх з попередженням
if ($data !== null) {
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    logApiRequest([
        'queue' => $requestAll ? 'all' : $queue,
        'source' => 'cache_outdated',
        'response_time_ms' => round($responseTime, 2),
        'success' => true,
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    if ($requestAll) {
        respondJSON([
            'success' => true,
            'warning' => 'Data is outdated. Could not fetch fresh data.',
            'queues' => $data['queues'] ?? [],
            'emergency_mode' => $data['emergency_mode'] ?? false,
            'updated' => $data['timestamp'] ?? null,
            'date' => $data['date'] ?? null,
            'source' => $data['source'] ?? 'unknown'
        ]);
    } else {
        respondJSON([
            'success' => true,
            'warning' => 'Data is outdated. Could not fetch fresh data.',
            'queue' => $queue,
            'schedule' => $data['schedule'] ?? '',
            'emergency_mode' => $data['emergency_mode'] ?? false,
            'updated' => $data['timestamp'] ?? null,
            'date' => $data['date'] ?? null,
            'source' => $data['source'] ?? 'unknown'
        ]);
    }
}

// Зовсім немає даних
$responseTime = (microtime(true) - $startTime) * 1000;

logApiRequest([
    'queue' => $requestAll ? 'all' : $queue,
    'source' => 'none',
    'response_time_ms' => round($responseTime, 2),
    'success' => false,
    'ip' => getClientIP(),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

respondJSON([
    'success' => false,
    'error' => 'No data available. All sources failed.'
], 503);

?>
