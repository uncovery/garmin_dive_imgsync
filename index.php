<?php

global $CONF, $timezoneObj;

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once('functions.inc.php');

// run all the pre-flight checks
checks();

// Set default timezone
date_default_timezone_set($CONF['timezone']);
$timezoneObj = new DateTimeZone($CONF['timezone']);

// Include the FitFileHandler class
include($CONF['fit_file_handlerclass']); // Make sure this path is correct

// Report header
echo "========================================\n";
echo "DIVE PHOTO PROCESSING STARTED\n";
echo "========================================\n";
echo "Photo extensions: " . implode(', ', $CONF['file_extensions']) . "\n";
echo "FIT folder: {$CONF['fitFolder']}\n";
echo "CSV output directory: {$CONF['csvOutputDir']}\n";
echo "Photo folder: {$CONF['photoFolder']}\n";
echo "FitCSVTool: {$CONF['fitCsvToolPath']}\n";
echo "Timezone: {$CONF['timezone']}\n";
echo "Update existing depth: " . ($CONF['update_existing_depth'] ? "YES" : "NO") . "\n";
echo "Update existing GPS: " . ($CONF['update_existing_gps'] ? "YES" : "NO") . "\n";
echo "========================================\n\n";


// Create FitFileHandler instance with CSV output directory
$fitHandler = new FitFileHandler(
    $CONF['fitCsvToolPath'], 
    $CONF['timezone'], 
    $CONF['required_data'], 
    $CONF['data_frequency'], 
    $CONF['csvOutputDir'], 
    $CONF['jsonOutputDir']
);

// Step 1: Scan all photos first and build file array
echo "Scanning all photos in photo folder...\n";
$allPhotos = scanAllPhotos();
echo "Found " . count($allPhotos) . " photos total\n\n";

// Step 2: Process all FIT files and collect dive data using the new method
echo "Scanning and processing FIT files...\n";
try {
    $divesData = $fitHandler->scanAndProcessFitFiles($CONF['fitFolder']);
    echo "Successfully processed " . count($divesData) . " FIT files\n\n";
} catch (Exception $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}

// Step 3: Match photos to dives and update XMP files
echo "Matching photos to dives and updating XMP files...\n";
echo "========================================\n";

$results = matchPhotosToDivesAndUpdate($allPhotos, $divesData);

// Report final results
echo "========================================\n";
echo "FINAL PROCESSING RESULTS:\n";
echo "========================================\n";
echo "Total photos processed: " . count($allPhotos) . "\n";
echo "Photos updated: " . $results['updated'] . "\n";
echo "Photos skipped: " . $results['skipped'] . "\n";
echo "Photos failed: " . $results['failed'] . "\n";
echo "========================================\n";
echo "PROCESSING COMPLETED!\n";
echo "========================================\n";

// Generate final report if enabled
if ($CONF['generateReport']) {
    echo "\n========================================\n";
    echo "GENERATING FINAL REPORT\n";
    echo "========================================\n";
    generatePhotoReport($allPhotos, $CONF['reportFile']);
    echo "========================================\n";
}

var_dump($fitHandler->debug_output);
