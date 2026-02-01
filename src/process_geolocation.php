#!/usr/bin/env php
<?php

/**
 * Asynchronous Geolocation Processor
 * 
 * This script processes photo index files to extract GPS EXIF data
 * and reverse geocode coordinates into country and city information.
 * 
 * Usage:
 *   php process_geolocation.php [options]
 * 
 * Options:
 *   --batch-size=N     Number of photos to process per index file (default: 10)
 *   --delay=N          Delay between API requests in milliseconds (default: 1100)
 *   --single-run       Process once and exit (default behavior)
 *   --continuous       Run continuously, processing periodically
 *   --interval=N       Interval in seconds between continuous runs (default: 60)
 *   --verbose          Enable verbose output
 *   --help             Show this help message
 * 
 * This script can be run:
 *   1. Manually from command line
 *   2. As a cron job (e.g., every 5 minutes)
 *   3. In continuous mode for long-running background processing
 * 
 * Example cron entry (run every 5 minutes):
 *   *\/5 * * * * /usr/bin/php /path/to/src/process_geolocation.php --batch-size=20
 */

require_once __DIR__ . '/geolocation.php';

// Default configuration
$config = [
    'batch_size' => 10,
    'delay_ms' => 2000,
    'single_run' => true,
    'interval' => 60,
    'verbose' => false
];

// Parse command line arguments
$options = getopt('', [
    'batch-size:',
    'delay:',
    'single-run',
    'continuous',
    'interval:',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
Asynchronous Geolocation Processor

This script processes photo index files to extract GPS EXIF data
and reverse geocode coordinates into country and city information.

Usage:
  php process_geolocation.php [options]

Options:
  --batch-size=N     Number of photos to process per index file (default: 10)
  --delay=N          Delay between API requests in milliseconds (default: 1100)
  --single-run       Process once and exit (default behavior)
  --continuous       Run continuously, processing periodically
  --interval=N       Interval in seconds between continuous runs (default: 60)
  --verbose          Enable verbose output
  --help             Show this help message

Examples:
  php process_geolocation.php --batch-size=20 --verbose
  php process_geolocation.php --continuous --interval=120

Note: This script respects OpenStreetMap Nominatim's rate limit of 1 request/second.

HELP;
    exit(0);
}

// Apply options
if (isset($options['batch-size'])) {
    $config['batch_size'] = (int)$options['batch-size'];
}
if (isset($options['delay'])) {
    $config['delay_ms'] = (int)$options['delay'];
}
if (isset($options['continuous'])) {
    $config['single_run'] = false;
}
if (isset($options['interval'])) {
    $config['interval'] = (int)$options['interval'];
}
if (isset($options['verbose'])) {
    $config['verbose'] = true;
}

// Set up temp directory path
$tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'temp';

/**
 * Log message to console (if verbose) and error log
 */
function logMessage($message, $isError = false) {
    global $config;
    
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] $message";
    
    if ($config['verbose'] || $isError) {
        echo $formattedMessage . PHP_EOL;
    }
    
    if ($isError) {
        error_log($formattedMessage);
    }
}

/**
 * Process all index files once
 */
function processAllIndexFiles($tempDir, $batchSize, $delayMicroseconds) {
    $totalStats = [
        'files_processed' => 0,
        'photos_processed' => 0,
        'photos_skipped' => 0,
        'photos_no_gps' => 0,
        'photos_already_geocoded' => 0,
        'errors' => 0
    ];
    
    // Check if temp directory exists
    if (!is_dir($tempDir)) {
        logMessage("Temp directory not found: $tempDir", true);
        return $totalStats;
    }
    
    // Find all folder picture index files
    $indexFiles = findFolderPictureIndexFiles($tempDir);
    
    if (empty($indexFiles)) {
        logMessage("No index files found in: $tempDir");
        return $totalStats;
    }
    
    logMessage("Found " . count($indexFiles) . " index file(s) to process");
    
    foreach ($indexFiles as $indexFile) {
        logMessage("Processing: " . basename($indexFile));
        
        // Get status before processing
        $statusBefore = getGeolocationStatus($indexFile);
        
        if ($statusBefore['pending'] === 0) {
            logMessage("  Skipping - all photos already processed");
            $totalStats['photos_already_geocoded'] += $statusBefore['geocoded'];
            continue;
        }
        
        // Process the index file
        $stats = updateIndexWithGeolocation($indexFile, $batchSize, $delayMicroseconds);
        
        $totalStats['files_processed']++;
        $totalStats['photos_processed'] += $stats['processed'];
        $totalStats['photos_skipped'] += $stats['skipped'];
        $totalStats['photos_no_gps'] += $stats['no_gps'];
        $totalStats['photos_already_geocoded'] += $stats['already_geocoded'];
        $totalStats['errors'] += $stats['errors'];
        
        // Get status after processing
        $statusAfter = getGeolocationStatus($indexFile);
        
        logMessage(sprintf(
            "  Processed: %d, No GPS: %d, Skipped: %d, Progress: %.1f%%",
            $stats['processed'],
            $stats['no_gps'],
            $stats['skipped'],
            $statusAfter['percent_complete']
        ));
    }
    
    return $totalStats;
}

/**
 * Main execution
 */
function main($config, $tempDir) {
    $delayMicroseconds = $config['delay_ms'] * 1000; // Convert ms to microseconds
    
    logMessage("Geolocation processor started");
    logMessage(sprintf(
        "Config: batch_size=%d, delay=%dms, mode=%s",
        $config['batch_size'],
        $config['delay_ms'],
        $config['single_run'] ? 'single-run' : 'continuous'
    ));
    
    do {
        $startTime = microtime(true);
        
        $stats = processAllIndexFiles($tempDir, $config['batch_size'], $delayMicroseconds);
        
        $elapsed = round(microtime(true) - $startTime, 2);
        
        logMessage(sprintf(
            "Run complete in %.2fs - Files: %d, Processed: %d, No GPS: %d, Already done: %d, Errors: %d",
            $elapsed,
            $stats['files_processed'],
            $stats['photos_processed'],
            $stats['photos_no_gps'],
            $stats['photos_already_geocoded'],
            $stats['errors']
        ));
        
        if (!$config['single_run']) {
            logMessage("Sleeping for {$config['interval']} seconds...");
            sleep($config['interval']);
        }
        
    } while (!$config['single_run']);
    
    logMessage("Geolocation processor finished");
}

// Run the main function
main($config, $tempDir);

?>
