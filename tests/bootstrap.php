<?php

// Bootstrap file for PHPUnit tests

// Start output buffering to capture any output during tests
ob_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Ensure session is available for tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Clean up output buffer
ob_end_clean();