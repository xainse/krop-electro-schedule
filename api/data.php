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
 * - getTelegramMessages() - читання історії
 * - cleanOldLogs() - очищення старих логів
 */

// Завантажуємо конфігурацію
require_once __DIR__ . '/config.php';

/**
 * Читає графіки з schedules.json
 * @param string|null $queue Номер черги (наприклад '1.1') або null для всіх
 * @return array|null Дані графіка або null при помилці
 */
function getSchedules($queue = null) {
    if (!file_exists(SCHEDULES_FILE)) {
        return null;
    }
    
    $data = @json_decode(file_get_contents(SCHEDULES_FILE), true);
    if (!$data) {
        return null;
    }
    
    if ($queue === null) {
        // Повертаємо всі черги
        return $data;
    }
    
    // Повертаємо конкретну чергу
    if (isset($data['queues'][$queue])) {
        return [
            'queue' => $queue,
            'schedule' => $data['queues'][$queue],
            'timestamp' => $data['timestamp'],
            'date' => $data['date'] ?? date('d.m.Y', $data['timestamp']),
            'emergency_mode' => $data['emergency_mode'] ?? false,
            'source' => $data['source'] ?? 'unknown'
        ];
    }
    
    return null;
}

/**
 * Зберігає графіки в schedules.json
 * @param array $queues Асоціативний масив черг ['1.1' => 'schedule', ...]
 * @param string $date Дата графіку (формат: DD.MM.YYYY)
 * @param bool $emergencyMode Чи активний ГАВ
 * @param string $source Джерело даних ('telegram' або 'kiroe.com.ua')
 * @param string $rawMessage Оригінальне повідомлення (опціонально)
 * @return bool Успіх операції
 */
function saveSchedules($queues, $date, $emergencyMode, $source, $rawMessage = '') {
    // Створюємо папку якщо її немає
    if (!is_dir(CACHE_DIR)) {
        if (!@mkdir(CACHE_DIR, 0755, true)) {
            return false;
        }
    }
    
    $data = [
        'timestamp' => time(),
        'date' => $date,
        'emergency_mode' => (bool)$emergencyMode,
        'source' => $source,
        'queues' => $queues
    ];
    
    if ($rawMessage) {
        $data['raw_message'] = $rawMessage;
    }
    
    // Валідація JSON перед збереженням
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    
    // Atomic write через temp file
    $tempFile = SCHEDULES_FILE . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    
    // Встановлюємо права доступу
    @chmod($tempFile, 0644);
    
    // Атомарно переміщуємо temp file на місце основного
    if (!@rename($tempFile, SCHEDULES_FILE)) {
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

/**
 * Перевіряє чи файл schedules.json існує та не порожній
 * @return bool
 */
function isDataEmpty() {
    if (!file_exists(SCHEDULES_FILE)) {
        return true;
    }
    
    $data = @json_decode(file_get_contents(SCHEDULES_FILE), true);
    return empty($data) || empty($data['queues']);
}

/**
 * Перевіряє чи дані в schedules.json свіжі
 * @param int $ttl Час життя в секундах
 * @return bool
 */
function isDataFresh($ttl = DATA_TTL) {
    if (!file_exists(SCHEDULES_FILE)) {
        return false;
    }
    
    $data = @json_decode(file_get_contents(SCHEDULES_FILE), true);
    if (!$data || !isset($data['timestamp'])) {
        return false;
    }
    
    $age = time() - $data['timestamp'];
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
 * Зберігає RSS повідомлення в rss_messages.json
 * @param array $messageData Дані повідомлення
 * @return bool Успіх операції
 */
function saveRSSMessage($messageData) {
    // Створюємо папку якщо її немає
    if (!is_dir(CACHE_DIR)) {
        if (!@mkdir(CACHE_DIR, 0755, true)) {
            return false;
        }
    }
    
    // Завантажуємо існуючі повідомлення
    $messages = [];
    if (file_exists(RSS_MESSAGES_FILE)) {
        $messages = @json_decode(file_get_contents(RSS_MESSAGES_FILE), true) ?: [];
    }
    
    // Додаємо нове повідомлення
    $messageData['saved_at'] = time();
    $messages[] = $messageData;
    
    // Обмежуємо кількість повідомлень (зберігаємо останні 100)
    if (count($messages) > 100) {
        $messages = array_slice($messages, -100);
    }
    
    // Валідація JSON перед збереженням
    $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    
    // Atomic write через temp file
    $tempFile = RSS_MESSAGES_FILE . '.tmp';
    if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    
    // Встановлюємо права доступу
    @chmod($tempFile, 0644);
    
    // Атомарно переміщуємо temp file на місце основного
    if (!@rename($tempFile, RSS_MESSAGES_FILE)) {
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

/**
 * Читає історію RSS повідомлень
 * @param int $limit Максимальна кількість повідомлень
 * @return array Масив повідомлень
 */
function getRSSMessages($limit = 100) {
    if (!file_exists(RSS_MESSAGES_FILE)) {
        return [];
    }
    
    $messages = @json_decode(file_get_contents(RSS_MESSAGES_FILE), true);
    if (!is_array($messages)) {
        return [];
    }
    
    // Повертаємо останні $limit повідомлень
    return array_slice($messages, -$limit);
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
 * Отримує IP адресу клієнта
 * @return string IP адреса
 */
function getClientIp() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
    
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
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
?>
