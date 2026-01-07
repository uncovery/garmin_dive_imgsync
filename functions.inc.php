<?php


/**
 * Scan all photos and build file array
 */
function scanAllPhotos() {
    global $CONF;
    
    $photos = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($CONF['photoFolder']));
    $totalFiles = 0;
    $checkedFiles = 0;
    
    foreach ($files as $file) {
        if ($file->isDir()) {
            continue;
        }
        $totalFiles++;
    }
    
    echo "Scanning $totalFiles files in photo folder...\n";
    
    foreach ($files as $file) {
        if ($file->isDir()) {
            continue;
        }

        $checkedFiles++;
        if ($checkedFiles % 100 === 0) {
            echo "Scanned $checkedFiles of $totalFiles files...\n";
        }
        
        $extension = strtolower($file->getExtension());
        
        if (in_array($extension, $CONF['file_extensions'])) {
            $filename = $file->getFilename();
            $pathinfo = pathinfo($filename);
            $baseName = $pathinfo['filename'];
            
            // Build XMP filename with correct case
            $xmpFile = $file->getPath() . '/' . $baseName . '.xmp';
            
            // Also check for XMP files with uppercase extension (.XMP)
            if (!file_exists($xmpFile)) {
                $xmpFileUpper = $file->getPath() . '/' . $baseName . '.XMP';
                if (file_exists($xmpFileUpper)) {
                    $xmpFile = $xmpFileUpper;
                }
            }
            
            if (file_exists($xmpFile)) {
                $photoTime = getPhotoTimeFromXmp($xmpFile); 
                
                if ($photoTime) {
                    $photos[] = [
                        'image' => $file->getPathname(),
                        'xmp' => $xmpFile,
                        'time' => $photoTime,
                        'has_existing_depth' => xmpHasDepthInfo($xmpFile),
                        'has_existing_gps' => xmpHasGPSInfo($xmpFile),
                        'updated' => false
                    ];
                }
            }
        }
    }
    
    echo "Scan completed. Found " . count($photos) . " photos with valid timestamps.\n";
    return $photos;
}

/**
 * Match photos to dives and update XMP files
 */
function matchPhotosToDivesAndUpdate($photos, $divesData) {
    global $CONF;
    
    $results = ['updated' => 0, 'skipped' => 0, 'failed' => 0];
    
    $diveTimes = [];
    foreach ($divesData as $dive) {
        $diveTimes[] = $dive['start_time']->format('Y-m-d H:i:s e') . " to " . $dive['end_time']->format('Y-m-d H:i:s e');
    }    
    echo "   Available dive windows: \n" . implode("\n", $diveTimes) . "\n";
    
    $default_data = array(
        'depth' => '', 
        'gps_lat' => '', 
        'gps_lon' => '', 
        'fit_file' => '', 
        'dive_start' => '',
        'dive_end' => '', 
        'time_diff' => '', 
        'matched_dive' => false,
    );
    
    foreach ($photos as $photoIndex => $photo) {
        $photoTime = $photo['time'];
        $filename = basename($photo['xmp']);
        $photoTimeFormatted = $photoTime->format('Y-m-d H:i:s e');
        // Initialize photo data for report with default values
        $photos[$photoIndex] = $default_data;
        
        // Find which dive this photo belongs to
        $matchingDive = null;
        foreach ($divesData as $dive) {
            // Convert both times to the same timezone for accurate comparison
            $diveStart = clone $dive['start_time'];
            $diveEnd = clone $dive['end_time'];

            if ($photoTime >= $diveStart && $photoTime <= $diveEnd) {
                $matchingDive = $dive;
                $photos[$photoIndex]['matched_dive'] = true;
                
                // Store dive information for report (even if we skip updating)
                $photos[$photoIndex]['fit_file'] = $matchingDive['fit_file'];
                $photos[$photoIndex]['dive_start'] = $matchingDive['start_time']->format('Y-m-d H:i:s e');
                $photos[$photoIndex]['dive_end'] = $matchingDive['end_time']->format('Y-m-d H:i:s e');
                break;
            }
        }
        
        if (!$matchingDive) {
            echo "⚠️ SKIPPED: $filename - Not within any dive time window (photo time: $photoTimeFormatted)\n";
            $results['skipped']++;
            // Ensure matched_dive is explicitly set to false
            $photos[$photoIndex]['matched_dive'] = false;
            continue;
        }
        
        // Find closest depth measurement (prefer earlier timestamps) for report
        $depthTimestamps = array_keys($matchingDive['depth_data']);
        $photoTimestamp = $photoTime->getTimestamp();
        $closestTimestamp = findClosestEarlierTimestamp($depthTimestamps, $photoTimestamp);
        $timeDifference = abs($closestTimestamp - $photoTimestamp);
        
        // depth_data[timestamp] is now an array like ['a' => depth, 'b' => temperature]
        $measurement = $matchingDive['depth_data'][$closestTimestamp] ?? null;

        if (!is_array($measurement) || !isset($measurement['a'])) {
            echo "❌ FAILED: $filename - Missing measurement at $closestTimestamp\n";
            $results['failed']++;
            continue;
        }

        $depth = (float)$measurement['a'];
        $temp  = isset($measurement['b']) ? (float)$measurement['b'] : null;
        
        // Store depth and time difference for report
        $photos[$photoIndex]['depth'] = round($depth, 2);
        $photos[$photoIndex]['temp']  = $temp !== null ? round($temp, 1) : '';
        $photos[$photoIndex]['time_diff'] = $timeDifference;
        
        // Store GPS data for report if available
        if ($matchingDive['gps_data']) {
            $photos[$photoIndex]['gps_lat'] = $matchingDive['gps_data']['lat_decimal'] ?? $matchingDive['gps_data']['latitude'];
            $photos[$photoIndex]['gps_lon'] = $matchingDive['gps_data']['lon_decimal'] ?? $matchingDive['gps_data']['longitude'];
        }
        
        // Skip if already has depth and GPS and we're not updating existing ones
        $skipDueToDepth = $photo['has_existing_depth'] && !$CONF['update_existing_depth'];
        $skipDueToGPS = $photo['has_existing_gps'] && !$CONF['update_existing_gps'];
        
        if ($skipDueToDepth && $skipDueToGPS) {
            echo "⚠️ SKIPPED: $filename - Already has depth and GPS information (photo time: $photoTimeFormatted)\n";
            $results['skipped']++;
            $photos[$photoIndex]['updated'] = false;
            continue;
        }
        
        // Update XMP file
        if (updateXmpWithData($photo['xmp'], $depth, $matchingDive['gps_data'])) {
            echo "✅ UPDATED: $filename - Depth: " . round($depth, 2) . "m (dive: " . $matchingDive['fit_file'] . ", time diff: " . $timeDifference . "s, photo time: $photoTimeFormatted), dive timestamp: $closestTimestamp\n";
            $results['updated']++;
            $photos[$photoIndex]['updated'] = true;
        } else {
            echo "❌ FAILED: $filename - Could not update XMP (photo time: $photoTimeFormatted)\n";
            $results['failed']++;
            $photos[$photoIndex]['updated'] = false;
        }
    }
    
    // Generate report after processing
    global $generateReport, $reportFile;
    if ($generateReport) {
        generatePhotoReport($photos, $reportFile);
    }
    
    return $results;
}


/**
 * Check if XMP file already has depth information
 */
function xmpHasDepthInfo($xmpFile) {
    if (!file_exists($xmpFile)) {
        return false;
    }
    
    $content = file_get_contents($xmpFile);
    if (!$content) {
        return false;
    }
    
    // Check if GPSAltitude attribute exists
    return preg_match('/exif:GPSAltitude="[^"]*"/', $content) && 
           preg_match('/exif:GPSAltitudeRef="[^"]*"/', $content);
}

/**
 * Check if XMP file already has GPS information
 */
function xmpHasGPSInfo($xmpFile) {
    if (!file_exists($xmpFile)) {
        return false;
    }
    
    $content = file_get_contents($xmpFile);
    if (!$content) {
        return false;
    }
    
    // Check if GPSLatitude and GPSLongitude attributes exist
    return preg_match('/exif:GPSLatitude="[^"]*"/', $content) && 
           preg_match('/exif:GPSLongitude="[^"]*"/', $content);
}

/**
 * Get photo creation time from XMP file (attribute-based format)
 * Returns DateTime object or false if no valid timestamp found
 */
function getPhotoTimeFromXmp($xmpFile) {
    global $timezoneObj;
    
    if (!file_exists($xmpFile)) {
        echo "    ❌ XMP file not found: " . basename($xmpFile) . "\n";
        return false;
    }
    
    $content = file_get_contents($xmpFile);
    if (!$content) {
        echo "    ❌ Could not read XMP file: " . basename($xmpFile) . "\n";
        return false;
    }
    
    // Look for xmp:CreateDate attribute in XMP (changed from exif:DateTimeOriginal)
    $matches = false;
    if (preg_match('/xmp:CreateDate="([^"]+)"/', $content, $matches)) {
        $dateStr = $matches[1];
        
        // Try different date formats
        $formats = [
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s.u',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.uP',
            'Y:m:d H:i:s'
        ];
        
        foreach ($formats as $format) {
            $dateTime = DateTime::createFromFormat($format, $dateStr);
            if ($dateTime) {
                // Convert to configured timezone
                $dateTime->setTimezone($timezoneObj);
                echo "    ✅ Found xmp:CreateDate: " . $dateTime->format('Y-m-d H:i:s e') 
                    . " in " . basename($xmpFile) . "\n";
                return $dateTime;
            }
        }
        
        // If standard formats fail, try to parse the date part only
        $timeMatch = false;
        if (preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $dateStr, $timeMatch)) {
            $dateTime = DateTime::createFromFormat('Y-m-d\TH:i:s', $timeMatch[1]);
            if ($dateTime) {
                // Convert to configured timezone
                $dateTime->setTimezone($timezoneObj);
                echo "    ✅ Found xmp:CreateDate (partial): " . $dateTime->format('Y-m-d H:i:s e') 
                    . " in " . basename($xmpFile) . "\n";
                return $dateTime;
            }
        }
        
        echo "    ❌ Could not parse xmp:CreateDate format: $dateStr in " . basename($xmpFile) . "\n";
        return false;
    }
    
    // Look for other timestamp attributes as fallback
    $timestampAttributes = [
        'exif:DateTimeOriginal', // Keep as fallback
        'xmp:ModifyDate',
        'photoshop:DateCreated'
    ];
    
    foreach ($timestampAttributes as $attr) {
        if (preg_match('/' . $attr . '="([^"]+)"/', $content, $matches)) {
            $dateStr = $matches[1];
            
            foreach ($formats as $format) {
                $dateTime = DateTime::createFromFormat($format, $dateStr);
                if ($dateTime) {
                    // Convert to configured timezone
                    $dateTime->setTimezone($timezoneObj);
                    echo "    ✅ Found $attr: " . $dateTime->format('Y-m-d H:i:s e') . " in " . basename($xmpFile) . "\n";
                    return $dateTime;
                }
            }
        }
    }
    
    echo "    ❌ CRITICAL: No valid timestamp attribute found in XMP file: " . basename($xmpFile) . "\n";
    echo "    → Checked for: xmp:CreateDate, exif:DateTimeOriginal, xmp:ModifyDate, photoshop:DateCreated\n";
    return false;
}

/**
 * Update XMP files with depth and GPS information
 */
function updatePhotosWithData($photos, $depthData, $gpsData) {
    global $CONF;
    
    if (empty($depthData)) {
        echo "    ❌ No depth data available for updating\n";
        return ['updated' => 0, 'skipped' => 0, 'failed' => count($photos)];
    }
    
    $depthTimestamps = array_keys($depthData);
    $results = ['updated' => 0, 'skipped' => 0, 'failed' => 0];
    
    foreach ($photos as $photo) {
        $photoTime = $photo['time']->getTimestamp();
        $filename = basename($photo['xmp']);
        
        // Skip if already has depth and we're not updating existing ones
        $skipDueToDepth = $photo['has_existing_depth'] && !$CONF['update_existing_depth'];
        $skipDueToGPS = $photo['has_existing_gps'] && !$CONF['update_existing_gps'];
        
        if ($skipDueToDepth && $skipDueToGPS) {
            echo "    ⚠️ SKIPPED: $filename - Already has depth and GPS information\n";
            $results['skipped']++;
            continue;
        }
        
        // Find closest depth measurement
        $closestTimestamp = findClosestTimestamp($depthTimestamps, $photoTime);
        $timeDifference = abs($closestTimestamp - $photoTime);
        $depth = $depthData[$closestTimestamp];
        
        if ($timeDifference <= 300) { // Within 5 minutes
            if (updateXmpWithData($photo['xmp'], $depth, $gpsData)) {
                echo "    ✅ UPDATED: $filename - Depth: " . round($depth, 2) . "m (time diff: " . $timeDifference . "s)\n";
                $results['updated']++;
            } else {
                echo "    ❌ FAILED: $filename - Could not update XMP\n";
                $results['failed']++;
            }
        } else {
            echo "    ⚠️ SKIPPED: $filename - No close measurement (diff: " . $timeDifference . "s, depth: " . round($depth, 2) . "m)\n";
            $results['skipped']++;
        }
    }
    
    return $results;
}

/**
 * Find closest timestamp using binary search
 */
function findClosestTimestamp($timestamps, $target) {
    $left = 0;
    $right = count($timestamps) - 1;
    $closest = $timestamps[0];
    $minDifference = PHP_INT_MAX;
    
    while ($left <= $right) {
        $mid = (int)(($left + $right) / 2);
        $current = $timestamps[$mid];
        $difference = abs($current - $target);
        
        if ($difference < $minDifference) {
            $minDifference = $difference;
            $closest = $current;
        }
        
        if ($current < $target) {
            $left = $mid + 1;
        } elseif ($current > $target) {
            $right = $mid - 1;
        } else {
            return $current; // Exact match
        }
    }
    
    return $closest;
}

/**
 * Find closest earlier timestamp using binary search
 * Prefers timestamps that are earlier than the target
 */
function findClosestEarlierTimestamp($timestamps, $target) {
    $left = 0;
    $right = count($timestamps) - 1;
    $closest = $timestamps[0];
    $minDifference = PHP_INT_MAX;
    
    // If all timestamps are after the target, return the first one
    if ($timestamps[0] > $target) {
        return $timestamps[0];
    }
    
    // If all timestamps are before the target, return the last one
    if ($timestamps[$right] < $target) {
        return $timestamps[$right];
    }
    
    // Binary search to find the closest earlier timestamp
    while ($left <= $right) {
        $mid = (int)(($left + $right) / 2);
        
        if ($timestamps[$mid] == $target) {
            return $timestamps[$mid]; // Exact match
        }
        
        if ($timestamps[$mid] < $target) {
            // This timestamp is earlier, check if it's closer than previous best
            $difference = $target - $timestamps[$mid];
            if ($difference < $minDifference) {
                $minDifference = $difference;
                $closest = $timestamps[$mid];
            }
            $left = $mid + 1;
        } else {
            $right = $mid - 1;
        }
    }
    
    return $closest;
}

/**
 * Update XMP file with depth and GPS information for attribute-based format
 */
function updateXmpWithData($xmpFile, $depth, $gpsData) {
    global $CONF;
    if (!file_exists($xmpFile)) {
        echo "      ❌ XMP file not found\n";
        return false;
    }
    
    $content = file_get_contents($xmpFile);
    if (!$content) {
        echo "      ❌ Could not read XMP file\n";
        return false;
    }
    
    $updated_gps = false;
    $updated_depth = false;
    
    // Update depth information if needed
    if ($CONF['update_existing_depth'] || preg_match('/exif:GPSAltitude="[^"]*"/', $content) == 0) {
        // Convert depth to positive value for GPSAltitude (below sea level)
        $depthValue = abs($depth);
        $depthFraction = $depthValue * 10000; // Convert to fraction like "120000/10000"
        
        echo "      Depth: " . round($depthValue, 2) . "m -> Fraction: " . $depthFraction . "/10000\n";
        
        // Update or add GPSAltitude attribute
        if (preg_match('/exif:GPSAltitude="[^"]*"/', $content)) {
            // Update existing GPSAltitude
            $content = preg_replace(
                '/exif:GPSAltitude="[^"]*"/',
                'exif:GPSAltitude="' . $depthFraction . '/10000"',
                $content
            );
            echo "      Updated existing GPSAltitude attribute; ";
        } else {
            // Add GPSAltitude attribute to the rdf:Description element
            if (preg_match('/(<rdf:Description[^>]*)/', $content, $matches)) {
                $content = str_replace(
                    $matches[1],
                    $matches[1] . "\n   exif:GPSAltitude=\"" . $depthFraction . "/10000\"",
                    $content
                );
                echo "      Added new GPSAltitude attribute; ";
            } else {
                echo "      ❌ Could not find rdf:Description element to add GPSAltitude\n";
                return false;
            }
        }
        
        // Update or add GPSAltitudeRef attribute
        if (preg_match('/exif:GPSAltitudeRef="[^"]*"/', $content)) {
            // Update existing GPSAltitudeRef
            $content = preg_replace(
                '/exif:GPSAltitudeRef="[^"]*"/',
                'exif:GPSAltitudeRef="1"',
                $content
            );
            echo "      Updated existing GPSAltitudeRef attribute; ";
        } else {
            // Add GPSAltitudeRef attribute to the rdf:Description element
            if (preg_match('/(<rdf:Description[^>]*)/', $content, $matches)) {
                $content = str_replace(
                    $matches[1],
                    $matches[1] . "\n   exif:GPSAltitudeRef=\"1\"",
                    $content
                );
                echo "      Added new GPSAltitudeRef attribute; ";
            }
        }
        
        $updated_depth = true;
    }
    
    // Update GPS information if available and needed
    if ($gpsData && ($CONF['update_existing_gps'] || preg_match('/exif:GPSLatitude="[^"]*"/', $content) == 0)) {
        // Update or add GPSLatitude attribute
        if (preg_match('/exif:GPSLatitude="[^"]*"/', $content)) {
            // Update existing GPSLatitude
            $content = preg_replace(
                '/exif:GPSLatitude="[^"]*"/',
                'exif:GPSLatitude="' . $gpsData['latitude'] . '"',
                $content
            );
            echo "      Updated existing GPSLatitude attribute; ";
        } else {
            // Add GPSLatitude attribute to the rdf:Description element
            if (preg_match('/(<rdf:Description[^>]*)/', $content, $matches)) {
                $content = str_replace(
                    $matches[1],
                    $matches[1] . "\n   exif:GPSLatitude=\"" . $gpsData['latitude'] . "\"",
                    $content
                );
                echo "      Added new GPSLatitude attribute; ";
            }
        }
        
        // Update or add GPSLongitude attribute
        if (preg_match('/exif:GPSLongitude="[^"]*"/', $content)) {
            // Update existing GPSLongitude
            $content = preg_replace(
                '/exif:GPSLongitude="[^"]*"/',
                'exif:GPSLongitude="' . $gpsData['longitude'] . '"',
                $content
            );
            echo "      Updated existing GPSLongitude attribute; ";
        } else {
            // Add GPSLongitude attribute to the rdf:Description element
            if (preg_match('/(<rdf:Description[^>]*)/', $content, $matches)) {
                $content = str_replace(
                    $matches[1],
                    $matches[1] . "\n   exif:GPSLongitude=\"" . $gpsData['longitude'] . "\"",
                    $content
                );
                echo "      Added new GPSLongitude attribute; ";
            }
        }
        
        $updated_gps = true;
    }
    
    // Write the updated content back to the file only if changes were made
if ($updated_depth || $updated_gps) {
        $result = file_put_contents($xmpFile, $content);
        if ($result === false) {
            echo "      ❌ Failed to write XMP file\n";
            return false;
        }
        
        echo "      ✅ Successfully updated XMP file\n";
        return true;
    } else {
        echo "      ⚠️ No updates needed for XMP file\n";
        return true;
    }
}

/**
 * Generate CSV report with all photo information
 */
function generatePhotoReport($photos, $reportFile) {
    if (empty($photos)) {
        echo "No photos to generate report for.\n";
        return false;
    }
    
    echo "Generating CSV report: $reportFile\n";
    
    $handle = fopen($reportFile, 'w');
    if (!$handle) {
        echo "❌ ERROR: Could not create report file: $reportFile\n";
        return false;
    }
    
    // Write CSV header
    fputcsv($handle, [
        'Filename',
        'Path',
        'Photo Time',
        'Depth (m)',
        'Temperature (C)',
        'GPS Latitude',
        'GPS Longitude',
        'FIT File Source',
        'Dive Start Time',
        'Dive End Time',
        'Time Difference (s)',
        'Has Existing Depth',
        'Has Existing GPS',
        'Matched Dive',
        'Status',
        'Notes'
    ]);
    
    $processedCount = 0;
    $matchedCount = 0;
    $missingInfoCount = 0;
    
    foreach ($photos as $photo) {
        $filename = basename($photo['xmp']);
        $path = dirname($photo['xmp']);
        $photoTime = $photo['time']->format('Y-m-d H:i:s e');
        
        // Default values with proper null checking
        $depth = $photo['depth'] ?? '';
        $gpsLat = $photo['gps_lat'] ?? '';
        $gpsLon = $photo['gps_lon'] ?? '';
        $fitFile = $photo['fit_file'] ?? '';
        $diveStart = $photo['dive_start'] ?? '';
        $diveEnd = $photo['dive_end'] ?? '';
        $timeDiff = $photo['time_diff'] ?? '';
        $temp = $photo['temp'] ?? '';
        
        // Safely check if matched_dive exists before using it
        $matchedDive = isset($photo['matched_dive']) ? ($photo['matched_dive'] ? 'YES' : 'NO') : 'UNKNOWN';
        
        $status = 'NOT PROCESSED';
        $notes = '';
        
        // Determine status and notes with safe array access
        $hasExistingDepth = $photo['has_existing_depth'] ?? false;
        $hasExistingGPS = $photo['has_existing_gps'] ?? false;
        
        if (isset($photo['matched_dive']) && $photo['matched_dive'] === false) {
            $status = 'NO MATCH';
            $notes = 'No matching dive found for this photo time';
            $missingInfoCount++;
        } else if (isset($photo['matched_dive']) && $photo['matched_dive'] === true) {
            $matchedCount++;
            if (isset($photo['updated'])) {
                if ($photo['updated']) {
                    $status = 'UPDATED';
                    $notes = 'Successfully updated with dive data';
                } else {
                    $status = 'SKIPPED';
                    $notes = 'Skipped - ';
                    if ($hasExistingDepth) $notes .= 'already has depth';
                    if ($hasExistingGPS) {
                        if ($hasExistingDepth) $notes .= ', ';
                        $notes .= 'already has GPS';
                    }
                }
            } else {
                $status = 'MATCHED BUT NOT PROCESSED';
                $notes = 'Matched to dive but not processed due to unknown reason';
            }
        } else {
            $status = 'UNKNOWN';
            $notes = 'Processing status unknown - matched_dive key not set';
        }
        
        fputcsv($handle, [
            $filename,
            $path,
            $photoTime,
            $depth,
            $temp,
            $gpsLat,
            $gpsLon,
            $fitFile,
            $diveStart,
            $diveEnd,
            $timeDiff,
            $hasExistingDepth ? 'YES' : 'NO',
            $hasExistingGPS ? 'YES' : 'NO',
            $matchedDive,
            $status,
            $notes
        ]);
        
        $processedCount++;
    }
    
    fclose($handle);
    
    echo "✅ Report generated successfully!\n";
    echo "   Total photos: $processedCount\n";
    echo "   Photos matched to dives: $matchedCount\n";
    echo "   Photos with no dive match: $missingInfoCount\n";
    echo "   Report saved to: $reportFile\n";
    
    return true;
}

/**
 * pre-flight checks
 * @global type $CONF
 */
function checks() {
    global $CONF;
    
    if (!file_exists('config.inc.php')) {
        echo "ATTENTION: edit config.inc.default.php and rename it to config.inc.php before proceedinG";
        die();
    }

    require_once('config.inc.php');

    if (!file_exists($CONF['fitCsvToolPath'])) {
        echo "ATTENTION: download FitCSVTool.jar from https://developer.garmin.com/fit/download/ and enter it's correct path in the config file!";
        die();
    }

    require_once('FitFileHandler.inc.php');

    // Check if folders exist
    if (!file_exists($CONF['fitFolder']) || !is_dir($CONF['fitFolder'])) {
        die("ERROR: FIT folder does not exist: {$CONF['fitFolder']}\n");
    }

    if (!file_exists($CONF['photoFolder']) || !is_dir($CONF['photoFolder'])) {
        die("ERROR: Photo folder does not exist: {$CONF['photoFolder']}\n");
    } 
}