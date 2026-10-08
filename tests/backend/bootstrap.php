<?php
/**
 * Bootstrap for PHPUnit tests.
 * Defines required constants and loads the source files under test.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Test runtime is isolated from production data and private overrides.
define('KROP_TEST_MODE', true);
$testRuntime = sys_get_temp_dir() . '/krop-tests-' . getmypid();
@mkdir($testRuntime, 0700, true);
define('CACHE_DIR', $testRuntime);
define('LOGS_DIR', $testRuntime . '/logs');
define('ENABLE_LOGGING', false);
require_once __DIR__ . '/../../api/bootstrap.php';

// Load parser.php
require_once __DIR__ . '/../../api/parser.php';

// Load site_fetcher.php (provides checkEmergencyModeInHTML for EmergencyModeTest)
require_once __DIR__ . '/../../api/site_fetcher.php';

// Note: parseAllQueues and checkEmergencyMode were removed from blackout.php.
// ParseAllQueuesTest now uses extractQueues() from parser.php.
// EmergencyModeTest uses checkEmergencyModeInHTML() from site_fetcher.php.
