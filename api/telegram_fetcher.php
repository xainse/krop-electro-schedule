<?php
/**
 * Telegram Fetcher
 * 
 * Отримує останні повідомлення через Telegram Bot API методом getUpdates
 * Викликається:
 * - З blackout_new.php якщо кеш порожній
 * - Через telegram_setup.php вручну
 * 
 * Функції:
 * - fetchFromTelegram() - отримання та парсинг повідомлень
 */

// Завантажуємо модулі
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/parser.php';

/**
 * Отримує останні повідомлення з Telegram та парсить графіки
 * @param int $limit Кількість повідомлень для отримання
 * @return array|false Дані графіку або false
 */
function fetchFromTelegram($limit = 10) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getUpdates";
    $url .= "?limit=" . $limit . "&allowed_updates=" . json_encode(['channel_post']);
    
    // Отримуємо дані через cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        error_log("Telegram API error: HTTP {$httpCode}, {$error}");
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['ok']) || !$data['ok']) {
        error_log("Telegram API returned error: " . json_encode($data));
        return false;
    }
    
    if (empty($data['result'])) {
        return false; // Немає нових повідомлень
    }
    
    // Обробляємо повідомлення від найновішого до найстарішого
    $updates = array_reverse($data['result']);
    
    foreach ($updates as $update) {
        // Обробляємо тільки channel_post
        if (!isset($update['channel_post'])) {
            continue;
        }
        
        $message = $update['channel_post'];
        
        // Перевіряємо обов'язкові поля
        if (!isset($message['text']) || !isset($message['message_id'])) {
            continue;
        }
        
        // Зберігаємо повідомлення в історію
        $messageData = [
            'message_id' => $message['message_id'],
            'chat_id' => $message['chat']['id'] ?? 0,
            'text' => $message['text'],
            'date' => $message['date'] ?? time(),
            'parsed' => false
        ];
        
        saveTelegramMessage($messageData);
        
        // Пробуємо розпарсити
        $parsed = parseScheduleMessage($message['text']);
        
        if ($parsed && !empty($parsed['queues'])) {
            // Графік знайдено - зберігаємо та повертаємо
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
                
                // Повертаємо розпарсені дані
                return $parsed;
            }
        }
    }
    
    return false; // Графік не знайдено в жодному повідомленні
}

// Якщо скрипт викликається напряму (не через require_once)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    // CLI режим
    $result = fetchFromTelegram();
    
    if ($result) {
        echo "✅ Графік завантажено успішно\n";
        echo "Дата: {$result['date']}\n";
        echo "Черг: " . count($result['queues']) . "\n";
        echo "ГАВ: " . ($result['emergency_mode'] ? 'Так' : 'Ні') . "\n";
    } else {
        echo "❌ Графік не знайдено\n";
    }
}
?>
