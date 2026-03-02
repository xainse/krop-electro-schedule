<?php
/**
 * Модуль для роботи з JSON файлами (кеш, логи, історія повідомлень)
 * 
 * Функції:
 * - getSchedules() - читання графіків
 * - saveSchedules() - збереження графіків
 * - isDataEmpty() - перевірка наявності даних
 * - isDataFresh() - перевірка актуальності
 * - logApiRequest() - логування запитів
 * - saveTelegramMessage() - збереження Telegram повідомлення
 * - getTelegramMessages() - читання історії Telegram
 * - cleanOldLogs() - очищення старих логів
 */

// Завантажуємо конфігурацію
require_once __DIR__ . '/config.php';

/**
 * Конвертує дату DD.MM.YYYY → YYYY-MM-DD (для ключів/сортування)
 */
function dateToKey($dateDMY) {
    $parts = explode('.', $dateDMY);
    if (count($parts) !== 3) return false;
    return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
}

/**
 * Конвертує дату YYYY-MM-DD → DD.MM.YYYY
 */
function keyToDate($dateISO) {
    $parts = explode('-', $dateISO);
    if (count($parts) !== 3) return false;
    return $parts[2] . '.' . $parts[1] . '.' . $parts[0];
}

/**
 * Завантажує та за потреби мігрує schedules.json у формат v2 (по датах)
 * @return array Структура v2: ['version' => 2, 'dates' => [...]]
 */
function loadSchedulesFile() {
    if (!file_exists(SCHEDULES_FILE)) {
        return ['version' => 2, 'dates' => []];
    }

    $raw = @json_decode(file_get_contents(SCHEDULES_FILE), true);
    if (!$raw || !is_array($raw)) {
        return ['version' => 2, 'dates' => []];
    }

    if (isset($raw['version']) && $raw['version'] === 2) {
        return $raw;
    }

    // Міграція зі старого формату (один об'єкт з полями date, queues, ...)
    if (isset($raw['queues']) && is_array($raw['queues'])) {
        $dateDMY = $raw['date'] ?? date('d.m.Y', $raw['timestamp'] ?? time());
        $key = dateToKey($dateDMY);
        if ($key === false) {
            return ['version' => 2, 'dates' => []];
        }
        return [
            'version' => 2,
            'dates' => [
                $key => [
                    'date' => $dateDMY,
                    'timestamp' => $raw['timestamp'] ?? time(),
                    'emergency_mode' => $raw['emergency_mode'] ?? false,
                    'source' => $raw['source'] ?? 'unknown',
                    'queues' => $raw['queues']
                ]
            ]
        ];
    }

    return ['version' => 2, 'dates' => []];
}

/**
 * Атомарно записує структуру v2 у schedules.json
 */
function writeSchedulesFile($data) {
    if (!is_dir(CACHE_DIR)) {
        if (!@mkdir(CACHE_DIR, 0755, true)) {
            return false;
        }
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $tempFile = SCHEDULES_FILE . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tempFile, 0644);

    if (!@rename($tempFile, SCHEDULES_FILE)) {
        @unlink($tempFile);
        return false;
    }
    return true;
}

/**
 * Видаляє з структури дати, які строго менші за сьогодні
 */
function cleanPastSchedules(&$data) {
    $todayKey = date('Y-m-d');
    foreach (array_keys($data['dates']) as $key) {
        if ($key < $todayKey) {
            unset($data['dates'][$key]);
        }
    }
}

/**
 * Визначає "актуальну" дату: найближча дата >= сьогодні серед збережених
 * @return string|null Ключ дати (YYYY-MM-DD) або null
 */
function resolveCurrentDateKey($data) {
    if (empty($data['dates'])) {
        return null;
    }
    $todayKey = date('Y-m-d');
    $keys = array_keys($data['dates']);
    sort($keys);

    foreach ($keys as $k) {
        if ($k >= $todayKey) {
            return $k;
        }
    }
    return null;
}

/**
 * Читає графіки з schedules.json (формат v2 — по датах)
 * @param string|null $queue Номер черги ('1.1') або null для всіх
 * @return array|null Дані графіка у сумісному форматі або null
 */
function getSchedules($queue = null) {
    $store = loadSchedulesFile();
    cleanPastSchedules($store);

    $dateKey = resolveCurrentDateKey($store);
    if ($dateKey === null) {
        return null;
    }

    $entry = $store['dates'][$dateKey];

    if ($queue === null) {
        return [
            'timestamp' => $entry['timestamp'],
            'date' => $entry['date'],
            'emergency_mode' => $entry['emergency_mode'] ?? false,
            'source' => $entry['source'] ?? 'unknown',
            'queues' => $entry['queues']
        ];
    }

    if (isset($entry['queues'][$queue])) {
        return [
            'queue' => $queue,
            'schedule' => $entry['queues'][$queue],
            'timestamp' => $entry['timestamp'],
            'date' => $entry['date'],
            'emergency_mode' => $entry['emergency_mode'] ?? false,
            'source' => $entry['source'] ?? 'unknown'
        ];
    }

    return null;
}

/**
 * Зберігає графіки в schedules.json (формат v2 — по датах)
 * @param array $queues Асоціативний масив черг ['1.1' => 'schedule', ...]
 * @param string $date Дата графіку (формат: DD.MM.YYYY)
 * @param bool $emergencyMode Чи активний ГАВ
 * @param string $source Джерело даних ('telegram' або 'kiroe.com.ua')
 * @param string $rawMessage Оригінальне повідомлення (опціонально)
 * @return bool Успіх операції
 */
function saveSchedules($queues, $date, $emergencyMode, $source, $rawMessage = '') {
    $store = loadSchedulesFile();

    $key = dateToKey($date);
    if ($key === false) {
        return false;
    }

    $entry = [
        'date' => $date,
        'timestamp' => time(),
        'emergency_mode' => (bool)$emergencyMode,
        'source' => $source,
        'queues' => $queues
    ];
    if ($rawMessage) {
        $entry['raw_message'] = $rawMessage;
    }

    $store['dates'][$key] = $entry;

    cleanPastSchedules($store);

    return writeSchedulesFile($store);
}

/**
 * Перевіряє чи є актуальні графіки (>= сьогодні)
 * @return bool
 */
function isDataEmpty() {
    $store = loadSchedulesFile();
    cleanPastSchedules($store);
    $dateKey = resolveCurrentDateKey($store);
    if ($dateKey === null) {
        return true;
    }
    return empty($store['dates'][$dateKey]['queues']);
}

/**
 * Перевіряє чи дані в schedules.json свіжі (для актуальної дати)
 * @param int $ttl Час життя в секундах
 * @return bool
 */
function isDataFresh($ttl = DATA_TTL) {
    $store = loadSchedulesFile();
    cleanPastSchedules($store);
    $dateKey = resolveCurrentDateKey($store);
    if ($dateKey === null) {
        return false;
    }
    $entry = $store['dates'][$dateKey];
    if (!isset($entry['timestamp'])) {
        return false;
    }
    $age = time() - $entry['timestamp'];
    return $age < $ttl;
}

/**
 * Логує запит до API в файл api_YYYY-MM-DD.log
 * @param array $data Дані для логування
 * @return void
 */
function logApiRequest($data) {
    if (!ENABLE_LOGGING) {
        return;
    }
    
    // Створюємо папку якщо її немає
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0755, true);
    }
    
    $date = date('Y-m-d');
    $logFile = LOGS_DIR . '/api_' . $date . '.log';
    
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
    
    // Записуємо з блокуванням
    @file_put_contents($logFile, $jsonLine, FILE_APPEND | LOCK_EX);
    
    // Встановлюємо права доступу
    if (file_exists($logFile)) {
        @chmod($logFile, 0644);
    }
}

/**
 * Видаляє старі log файли
 * @param int $daysToKeep Скільки днів зберігати
 * @return int Кількість видалених файлів
 */
function cleanOldLogs($daysToKeep = 30) {
    if (!is_dir(LOGS_DIR)) {
        return 0;
    }
    
    $cutoffTime = time() - ($daysToKeep * 86400);
    $deleted = 0;
    
    $files = glob(LOGS_DIR . '/*.log');
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}


/**
 * Зберігає Telegram повідомлення в telegram_messages.json
 * @param array $messageData Дані повідомлення
 * @return bool Успіх операції
 */
function saveTelegramMessage($messageData) {
    // Створюємо папку якщо її немає
    if (!is_dir(CACHE_DIR)) {
        if (!@mkdir(CACHE_DIR, 0755, true)) {
            return false;
        }
    }
    
    // Завантажуємо існуючі дані
    $data = [
        'last_id' => 0,
        'last_check' => time(),
        'messages' => []
    ];
    
    if (file_exists(TELEGRAM_MESSAGES_FILE)) {
        $existing = @json_decode(file_get_contents(TELEGRAM_MESSAGES_FILE), true);
        if ($existing && is_array($existing)) {
            $data = $existing;
        }
    }
    
    // Додаємо нове повідомлення
    $messageData['saved_at'] = time();
    
    // Перевіряємо чи повідомлення вже існує (за ID)
    $exists = false;
    foreach ($data['messages'] as $key => $msg) {
        if ($msg['id'] === $messageData['id']) {
            // Оновлюємо існуюче
            $data['messages'][$key] = $messageData;
            $exists = true;
            break;
        }
    }
    
    if (!$exists) {
        $data['messages'][] = $messageData;
    }
    
    // Оновлюємо last_check
    $data['last_check'] = time();
    
    // Обмежуємо кількість повідомлень (зберігаємо останні 100)
    if (count($data['messages']) > 100) {
        // Сортуємо за message_num від найбільшого до найменшого
        usort($data['messages'], function($a, $b) {
            return $b['message_num'] - $a['message_num'];
        });
        $data['messages'] = array_slice($data['messages'], 0, 100);
    }
    
    // Валідація JSON перед збереженням
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    
    // Atomic write через temp file
    $tempFile = TELEGRAM_MESSAGES_FILE . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    
    // Встановлюємо права доступу
    @chmod($tempFile, 0644);
    
    // Атомарно переміщуємо temp file на місце основного
    if (!@rename($tempFile, TELEGRAM_MESSAGES_FILE)) {
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

/**
 * Читає історію Telegram повідомлень
 * @param int $limit Максимальна кількість повідомлень
 * @return array Масив повідомлень
 */
function getTelegramMessages($limit = 100) {
    if (!file_exists(TELEGRAM_MESSAGES_FILE)) {
        return [];
    }
    
    $data = @json_decode(file_get_contents(TELEGRAM_MESSAGES_FILE), true);
    if (!is_array($data) || !isset($data['messages'])) {
        return [];
    }
    
    $messages = $data['messages'];
    
    // Сортуємо від найновішого до найстарішого
    usort($messages, function($a, $b) {
        return $b['message_num'] - $a['message_num'];
    });
    
    // Повертаємо останні $limit повідомлень
    return array_slice($messages, 0, $limit);
}

/**
 * Отримує ID останнього обробленого Telegram повідомлення
 * @return int|null ID або null якщо ще не було обробки
 */
function getLastTelegramId() {
    if (!file_exists(TELEGRAM_MESSAGES_FILE)) {
        return null;
    }
    
    $data = @json_decode(file_get_contents(TELEGRAM_MESSAGES_FILE), true);
    if (!is_array($data) || !isset($data['last_id'])) {
        return null;
    }
    
    return $data['last_id'] > 0 ? $data['last_id'] : null;
}

/**
 * Зберігає ID останнього обробленого Telegram повідомлення
 * @param int $id ID повідомлення
 * @return bool Успіх операції
 */
function saveLastTelegramId($id) {
    // Створюємо папку якщо її немає
    if (!is_dir(CACHE_DIR)) {
        if (!@mkdir(CACHE_DIR, 0755, true)) {
            return false;
        }
    }
    
    // Завантажуємо існуючі дані
    $data = [
        'last_id' => 0,
        'last_check' => time(),
        'messages' => []
    ];
    
    if (file_exists(TELEGRAM_MESSAGES_FILE)) {
        $existing = @json_decode(file_get_contents(TELEGRAM_MESSAGES_FILE), true);
        if ($existing && is_array($existing)) {
            $data = $existing;
        }
    }
    
    // Оновлюємо last_id тільки якщо новий ID більший
    if ($id > $data['last_id']) {
        $data['last_id'] = $id;
        $data['last_check'] = time();
    }
    
    // Валідація JSON перед збереженням
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    
    // Atomic write через temp file
    $tempFile = TELEGRAM_MESSAGES_FILE . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    
    // Встановлюємо права доступу
    @chmod($tempFile, 0644);
    
    // Атомарно переміщуємо temp file на місце основного
    if (!@rename($tempFile, TELEGRAM_MESSAGES_FILE)) {
        @unlink($tempFile);
        return false;
    }
    
    return true;
}
?>
