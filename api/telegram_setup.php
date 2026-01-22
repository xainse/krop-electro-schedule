<?php
/**
 * Telegram Setup Script
 * 
 * Веб-інтерфейс для налаштування Telegram бота
 * URL: https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=ACTION
 * 
 * Дії:
 * - init - створити структуру папок
 * - set_webhook - встановити webhook
 * - delete_webhook - видалити webhook
 * - info - інформація про бота
 * - fetch - завантажити останні повідомлення
 * - test - тест з'єднання з Telegram API
 * 
 * ВАЖЛИВО: Після налаштування видалити цей файл або змінити пароль!
 */

// Завантажуємо конфігурацію
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/telegram_fetcher.php';

// HTML шаблон для виводу
function outputHTML($title, $message, $isError = false) {
    $color = $isError ? '#dc2626' : '#16a34a';
    $icon = $isError ? '❌' : '✅';
    
    echo "<!DOCTYPE html>
<html lang='uk'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$title}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .message { background: #f3f4f6; padding: 20px; border-radius: 8px; border-left: 4px solid {$color}; }
        .icon { font-size: 24px; }
        pre { background: #1f2937; color: #f9fafb; padding: 15px; border-radius: 4px; overflow-x: auto; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .actions { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; background: #2563eb; color: white; border-radius: 4px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class='message'>
        <div class='icon'>{$icon}</div>
        <h2>{$title}</h2>
        <div>{$message}</div>
    </div>
</body>
</html>";
}

// 1. Перевірка пароля
$password = $_GET['password'] ?? '';
if ($password !== SETUP_PASSWORD) {
    outputHTML('Помилка доступу', 'Невірний пароль. Перевірте параметр password в URL.', true);
    exit;
}

// 2. Отримуємо дію
$action = $_GET['action'] ?? 'info';

// 3. Виконуємо дію
switch ($action) {
    case 'init':
        // Створюємо структуру папок
        $errors = [];
        
        if (!is_dir(CACHE_DIR)) {
            if (@mkdir(CACHE_DIR, 0755, true)) {
                $created[] = CACHE_DIR;
            } else {
                $errors[] = "Не вдалося створити: " . CACHE_DIR;
            }
        }
        
        if (!is_dir(LOGS_DIR)) {
            if (@mkdir(LOGS_DIR, 0755, true)) {
                $created[] = LOGS_DIR;
            } else {
                $errors[] = "Не вдалося створити: " . LOGS_DIR;
            }
        }
        
        // Створюємо .htaccess для cache
        $htaccessContent = "# Заборона прямого доступу до JSON файлів\n<FilesMatch \"\\.(json)$\">\n    Require all denied\n</FilesMatch>";
        @file_put_contents(CACHE_DIR . '/.htaccess', $htaccessContent);
        
        if (empty($errors)) {
            outputHTML('Ініціалізація завершена', '
                Структура папок створена успішно:<br>
                - ' . CACHE_DIR . '<br>
                - ' . LOGS_DIR . '<br><br>
                <div class="actions">
                    <a href="?password=' . urlencode($password) . '&action=test" class="btn">Тест з\'єднання</a>
                    <a href="?password=' . urlencode($password) . '&action=set_webhook" class="btn">Встановити webhook</a>
                </div>
            ');
        } else {
            outputHTML('Помилка ініціалізації', implode('<br>', $errors), true);
        }
        break;
    
    case 'test':
        // Тест з'єднання з Telegram API
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getMe";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if ($data['ok']) {
                $bot = $data['result'];
                outputHTML('Тест успішний', '
                    З\'єднання з Telegram API працює!<br><br>
                    <strong>Інформація про бота:</strong><br>
                    ID: ' . $bot['id'] . '<br>
                    Username: @' . $bot['username'] . '<br>
                    Ім\'я: ' . $bot['first_name'] . '<br><br>
                    <div class="actions">
                        <a href="?password=' . urlencode($password) . '&action=set_webhook" class="btn">Встановити webhook</a>
                        <a href="?password=' . urlencode($password) . '&action=info" class="btn">Детальна інформація</a>
                    </div>
                ');
            } else {
                outputHTML('Помилка API', 'Telegram API повернув помилку: ' . json_encode($data), true);
            }
        } else {
            outputHTML('Помилка з\'єднання', 'HTTP код: ' . $httpCode . '<br>Перевірте TELEGRAM_BOT_TOKEN в config.php', true);
        }
        break;
    
    case 'set_webhook':
        // Встановлюємо webhook
        $webhookUrl = 'https://xain.in.ua/api/telegram_webhook.php?secret=' . TELEGRAM_WEBHOOK_SECRET;
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/setWebhook";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['url' => $webhookUrl]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if ($data['ok']) {
                outputHTML('Webhook встановлено', '
                    Webhook успішно налаштовано!<br><br>
                    <strong>URL:</strong> ' . htmlspecialchars($webhookUrl) . '<br><br>
                    Тепер бот буде отримувати повідомлення з каналу автоматично.<br><br>
                    <div class="actions">
                        <a href="?password=' . urlencode($password) . '&action=info" class="btn">Перевірити статус</a>
                        <a href="?password=' . urlencode($password) . '&action=fetch" class="btn">Завантажити історію</a>
                    </div>
                ');
            } else {
                outputHTML('Помилка встановлення webhook', 'Telegram API повернув помилку: <pre>' . json_encode($data, JSON_PRETTY_PRINT) . '</pre>', true);
            }
        } else {
            outputHTML('Помилка з\'єднання', 'HTTP код: ' . $httpCode, true);
        }
        break;
    
    case 'delete_webhook':
        // Видаляємо webhook
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/deleteWebhook";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        
        if ($data['ok']) {
            outputHTML('Webhook видалено', 'Webhook успішно видалено.');
        } else {
            outputHTML('Помилка', 'Не вдалося видалити webhook: ' . json_encode($data), true);
        }
        break;
    
    case 'fetch':
        // Завантажуємо останні повідомлення
        $result = fetchFromTelegram(20);
        
        if ($result) {
            outputHTML('Графік завантажено', '
                Графік успішно завантажено з Telegram!<br><br>
                <strong>Дата:</strong> ' . $result['date'] . '<br>
                <strong>Черг:</strong> ' . count($result['queues']) . '<br>
                <strong>ГАВ:</strong> ' . ($result['emergency_mode'] ? 'Так' : 'Ні') . '<br><br>
                <div class="actions">
                    <a href="' . dirname($_SERVER['SCRIPT_NAME']) . '/blackout.php?queue=1.1" class="btn" target="_blank">Тест API</a>
                    <a href="?password=' . urlencode($password) . '&action=info" class="btn">Інформація</a>
                </div>
            ');
        } else {
            outputHTML('Графік не знайдено', '
                Не вдалося знайти графік в останніх повідомленнях.<br><br>
                Можливі причини:<br>
                - Бот не доданий до каналу як адміністратор<br>
                - В каналі немає повідомлень з графіком<br>
                - Формат повідомлення не розпізнано<br><br>
                <div class="actions">
                    <a href="?password=' . urlencode($password) . '&action=info" class="btn">Перевірити статус</a>
                </div>
            ', true);
        }
        break;
    
    case 'info':
    default:
        // Отримуємо інформацію про бота та webhook
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getWebhookInfo";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if ($data && $data['ok']) {
            $info = $data['result'];
            $hasWebhook = !empty($info['url']);
            
            // Перевіряємо наявність даних
            $hasData = file_exists(SCHEDULES_FILE);
            $dataInfo = '';
            if ($hasData) {
                $schedules = getSchedules();
                if ($schedules) {
                    $dataInfo = '<br><strong>Дані:</strong> ' . date('d.m.Y H:i', $schedules['timestamp']) . ' (' . count($schedules['queues']) . ' черг)';
                }
            }
            
            outputHTML('Інформація про бота', '
                <strong>Статус webhook:</strong> ' . ($hasWebhook ? '✅ Активний' : '❌ Не встановлено') . '<br>
                ' . ($hasWebhook ? '<strong>URL:</strong> ' . htmlspecialchars($info['url']) . '<br>' : '') . '
                <strong>Останніх оновлень:</strong> ' . ($info['pending_update_count'] ?? 0) . '<br>
                ' . ($info['last_error_message'] ?? '') . '
                ' . $dataInfo . '<br><br>
                <strong>Доступні дії:</strong><br>
                <div class="actions">
                    ' . (!$hasWebhook ? '<a href="?password=' . urlencode($password) . '&action=set_webhook" class="btn">Встановити webhook</a>' : '<a href="?password=' . urlencode($password) . '&action=delete_webhook" class="btn">Видалити webhook</a>') . '
                    <a href="?password=' . urlencode($password) . '&action=fetch" class="btn">Завантажити повідомлення</a>
                    <a href="?password=' . urlencode($password) . '&action=test" class="btn">Тест з\'єднання</a>
                </div>
            ');
        } else {
            outputHTML('Помилка', 'Не вдалося отримати інформацію про бота. Перевірте TELEGRAM_BOT_TOKEN в config.php', true);
        }
        break;
}
?>
