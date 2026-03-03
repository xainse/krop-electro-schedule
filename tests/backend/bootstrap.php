<?php
/**
 * Bootstrap for PHPUnit tests.
 * Defines required constants and loads the source files under test.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load config (required for site_fetcher)
$configPath = __DIR__ . '/../../api/config.php';
if (!file_exists($configPath)) {
    require_once __DIR__ . '/../../api/config.example.php';
} else {
    require_once $configPath;
}

// Load parser.php
require_once __DIR__ . '/../../api/parser.php';

// Load site_fetcher.php (provides checkEmergencyModeInHTML for EmergencyModeTest)
require_once __DIR__ . '/../../api/site_fetcher.php';

// Note: parseAllQueues and checkEmergencyMode were removed from blackout.php.
// ParseAllQueuesTest now uses extractQueues() from parser.php.
// EmergencyModeTest uses checkEmergencyModeInHTML() from site_fetcher.php.
