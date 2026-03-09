<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap — FideX PHP Reference Implementation
 *
 * Sets up the test environment before any tests run.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load test environment variables from phpunit.xml <php> section
// (already set by PHPUnit before this file runs)

// Ensure storage directories exist for test run
$dirs = [
    __DIR__ . '/../storage/logs',
    __DIR__ . '/../storage/coverage',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
