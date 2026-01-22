<?php
/**
 * Telegram Webhook Handler
 * 
 * Приймає повідомлення від Telegram Bot API через webhook
 * URL: https://xain.in.ua/api/telegram_webhook.php?secret=YOUR_SECRET
 * 
 * Логіка:
 * 1. Перевірка безпеки (secret параметр)
 * 2. Валідація даних від Telegram
 * 3. Збереження повідомлення в історію
 * 4. Парсинг через parser.php
 * 5. Збереження графіку якщо знайдено
 */

// Вимикаємо вивід помилок
error_reporting(0);
ini_set('display_errors', '0');

// Встановлюємо заголовок
header('Content-Type: application/json');

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/parser.php';

// Функція для логування помилок в окремий файл
function logWebhookError($message, $data = null) {
    $logFile = LOGS_DIR . '/telegram_webhook_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] {$message}";
    if ($data) {
        $logLine .= ' | Data: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $logLine .= "\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// 1. Перевірка безпеки - secret параметр
$secret = $_GET['secret'] ?? '';
if ($secret !== TELEGRAM_WEBHOOK_SECRET) {
    logWebhookError('Invalid secret', ['provided' => $secret]);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// 2. Читаємо вхідні дані
$input = file_get_contents('php://input');
if (empty($input)) {
    logWebhookError('Empty input');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty request']);
    exit;
}

// 3. Парсимо JSON
$update = json_decode($input, true);
if (!$update) {
    logWebhookError('Invalid JSON', ['input' => substr($input, 0, 500)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// 4. Валідація структури Telegram update
// Нас цікавлять тільки channel_post (повідомлення з каналу)
if (!isset($update['channel_post'])) {
    // Не channel_post - ігноруємо, але повертаємо OK
    echo json_encode(['ok' => true, 'message' => 'Not a channel post']);
    exit;
}

$message = $update['channel_post'];

// Перевіряємо обов'язкові поля
if (!isset($message['message_id']) || !isset($message['chat']['id']) || !isset($message['text'])) {
    logWebhookError('Missing required fields', ['message' => $message]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing fields']);
    exit;
}

// 5. Перевірка розміру повідомлення
if (strlen($message['text']) > MAX_MESSAGE_SIZE) {
    logWebhookError('Message too large', ['size' => strlen($message['text'])]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message too large']);
    exit;
}

// 6. Зберігаємо повідомлення в історію
$messageData = [
    'message_id' => $message['message_id'],
    'chat_id' => $message['chat']['id'],
    'text' => $message['text'],
    'date' => $message['date'],
    'parsed' => false
];

saveTelegramMessage($messageData);

// 7. Пробуємо розпарсити повідомлення
$parsed = parseScheduleMessage($message['text']);

if ($parsed && !empty($parsed['queues'])) {
    // Графік знайдено - зберігаємо
    $saved = saveSchedules(
        $parsed['queues'],
        $parsed['date'],
        $parsed['emergency_mode'],
        SOURCE_TELEGRAM,
        $message['text']
    );
    
    if ($saved) {
        // Оновлюємо статус повідомлення
        $messageData['parsed'] = true;
        saveTelegramMessage($messageData);
        
        logWebhookError('Schedule saved', [
            'date' => $parsed['date'],
            'queues' => count($parsed['queues']),
            'emergency_mode' => $parsed['emergency_mode']
        ]);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Schedule updated',
            'date' => $parsed['date'],
            'queues_count' => count($parsed['queues'])
        ]);
    } else {
        logWebhookError('Failed to save schedule');
        echo json_encode(['ok' => false, 'error' => 'Save failed']);
    }
} else {
    // Графік не знайдено - це нормально, не всі повідомлення містять графіки
    echo json_encode(['ok' => true, 'message' => 'No schedule found']);
}
?>
