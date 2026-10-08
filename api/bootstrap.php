<?php
// Optional private overrides; the public scraper needs no bot credentials.
if (!defined('KROP_TEST_MODE') && is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
date_default_timezone_set('Europe/Kyiv');
$defaults = [
    'CACHE_DIR' => __DIR__ . '/cache',
    'LOGS_DIR' => __DIR__ . '/logs',
    'TELEGRAM_CHANNEL_URL' => 'https://t.me/s/SvitloKropyvnytskyiMisto',
    'TELEGRAM_CHECK_INTERVAL' => 300,
    'DATA_TTL' => 600,
    'ENABLE_LOGGING' => true,
    'SOURCE_TELEGRAM' => 'telegram',
    'SOURCE_SITE' => 'kiroe.com.ua',
    'SITE_URL' => 'https://kiroe.com.ua/electricity-blackout',
];
foreach ($defaults as $name => $value) {
    if (!defined($name)) define($name, $value);
}
if (!defined('SCHEDULES_FILE')) define('SCHEDULES_FILE', CACHE_DIR . '/schedules.json');
if (!defined('TELEGRAM_MESSAGES_FILE')) define('TELEGRAM_MESSAGES_FILE', CACHE_DIR . '/telegram_messages.json');
