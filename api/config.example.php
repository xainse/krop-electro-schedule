<?php
// Optional overrides: copy to config.php. Never commit private credentials.
// No Telegram bot token is required: this app reads a public channel.
define('TELEGRAM_CHANNEL_URL', 'https://t.me/s/SvitloKropyvnytskyiMisto');
define('TELEGRAM_CHECK_INTERVAL', 300);
define('CACHE_DIR', __DIR__ . '/cache');
define('LOGS_DIR', __DIR__ . '/logs');
define('ENABLE_LOGGING', true);
