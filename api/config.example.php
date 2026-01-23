<?php
/**
 * Приклад конфігурації для Telegram Bot інтеграції
 * 
 * Скопіюйте цей файл як config.php і заповніть своїми значеннями
 * ВАЖЛИВО: config.php не повинен бути в git (вже додано в .gitignore)
 */

// Telegram Bot налаштування
define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
define('TELEGRAM_WEBHOOK_SECRET', 'RANDOM_SECRET_KEY_32_CHARS');

// Setup скрипт (для налаштування через браузер)
define('SETUP_PASSWORD', 'CHANGE_THIS_PASSWORD_123');

// Шляхи до файлів
define('CACHE_DIR', __DIR__ . '/cache');
define('SCHEDULES_FILE', CACHE_DIR . '/schedules.json');
define('MESSAGES_FILE', CACHE_DIR . '/telegram_messages.json');

// Безпека
define('API_RATE_LIMIT', 100); // запитів на хвилину з одного IP
define('MAX_MESSAGE_SIZE', 10000); // максимальний розмір повідомлення

// Джерела даних
define('SOURCE_TELEGRAM', 'telegram');
define('SOURCE_SITE', 'kiroe.com.ua');
define('SITE_URL', 'https://kiroe.com.ua/electricity-blackout');

// TTL для даних (в секундах)
define('DATA_TTL', 86400); // 24 години

// Логування
define('LOGS_DIR', __DIR__ . '/logs');
define('ENABLE_LOGGING', true);
?>
