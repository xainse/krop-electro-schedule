<?php
// PHP and private runtime files must be owned by the application's service user.
umask(0077);
ini_set('display_errors', '0');
// Optional private overrides; the public scraper needs no bot credentials.
if (!defined('KROP_TEST_MODE')) {
    $privateConfig = getenv('KROP_CONFIG_FILE');
    if ($privateConfig !== false && $privateConfig !== '') {
        if ($privateConfig[0] !== '/' || !is_file($privateConfig) || !is_readable($privateConfig)) {
            throw new RuntimeException('Invalid private configuration path');
        }
        require_once $privateConfig;
    } elseif (is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
}
$runtimeRoot = defined('KROP_TEST_MODE') ? false : getenv('KROP_RUNTIME_DIR');
if ($runtimeRoot !== false && $runtimeRoot !== '' && $runtimeRoot[0] !== '/') {
    throw new RuntimeException('Runtime directory must be absolute');
}
$runtimeRoot = $runtimeRoot ? rtrim($runtimeRoot, '/') : __DIR__;
date_default_timezone_set('Europe/Kyiv');
$defaults = [
    'CACHE_DIR' => $runtimeRoot . '/cache',
    'LOGS_DIR' => $runtimeRoot . '/logs',
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
