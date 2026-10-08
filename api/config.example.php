<?php
// Optional overrides: copy to config.php. Never commit private credentials.
// Prefer a file outside webroot, selected by the server's KROP_CONFIG_FILE.
// KROP_RUNTIME_DIR=/absolute/private/runtime controls default cache/logs paths.
// Explicit CACHE_DIR/LOGS_DIR constants below would take precedence over that env var.
// No Telegram bot token is required: this app reads a public channel.
define('TELEGRAM_CHANNEL_URL', 'https://t.me/s/SvitloKropyvnytskyiMisto');
define('TELEGRAM_CHECK_INTERVAL', 300);
// define('CACHE_DIR', '/absolute/private/runtime/cache');
// define('LOGS_DIR', '/absolute/private/runtime/logs');
define('ENABLE_LOGGING', true);
