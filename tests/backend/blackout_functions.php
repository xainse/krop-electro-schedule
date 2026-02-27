<?php
/**
 * Extracts testable function definitions from blackout.php.
 * These functions depend only on normalizeSchedule() from parser.php.
 */

$blackoutSource = file_get_contents(__DIR__ . '/../../api/blackout.php');

// Extract parseAllQueues function
if (preg_match('/^function parseAllQueues\(.+?\n\}/ms', $blackoutSource, $m)) {
    eval($m[0]);
}

// Extract checkEmergencyMode function
if (preg_match('/^function checkEmergencyMode\(.+?\n\}/ms', $blackoutSource, $m)) {
    eval($m[0]);
}
