<?php
/**
 * Bootstrap for PHPUnit tests.
 * Defines required constants and loads the source files under test.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load parser.php (standalone, no dependencies)
require_once __DIR__ . '/../../api/parser.php';

// Load functions from blackout.php that we want to test.
// blackout.php has side effects (headers, requires, etc.), so we extract
// just the function definitions we need.
require_once __DIR__ . '/blackout_functions.php';
