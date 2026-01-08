<?php

class FitFileHandler {
    // Garmin FIT timestamp offset (seconds between 1989-12-31 and 1970-01-01)
    const FIT_TIMESTAMP_OFFSET = 631065600;
    
    private $fitCsvToolPath;
    private $timezone;
    private $timezoneObj;
    private $csvOutputDir;
    private $jsonOutputDir;
    private $required_data;
    private $data_frequency;
    public $debug_output;
    
    public function __construct($fitCsvToolPath, $timezone, $required_data, $data_frequency, $csvOutputDir, $jsonOutputDir) {
        $this->fitCsvToolPath = $fitCsvToolPath;
        $this->timezone = $timezone;
        $this->timezoneObj = new DateTimeZone($timezone);
        $this->required_data = $required_data;
        $this->data_frequency = $data_frequency;
        $this->csvOutputDir = $csvOutputDir;
        $this->jsonOutputDir = $jsonOutputDir;
    }
    
    /**
     * Scan and process all FIT files in a directory
     */
    public function scanAndProcessFitFiles($fitFolder) {
        $fileList = $this->findFitFilesRegex($fitFolder);
        
        $this->debug_output[] = "DEBUG: Found " . count($fileList) . " FIT files\n";    
        
        $divesData = array();
        
        $index = 0;
        foreach ($fileList as $fitFile) {
            $index++;
            $this->debug_output[] = "PROCESSING FILE $index";
            $this->debug_output[] = "----------------------------------------";
            $this->debug_output[] = var_export($fitFile, true);
            
            $start = strlen($fitFolder);
            $end = -strlen(basename($fitFile));
            $subfolder = substr($fitFile, $start, $end);
            
            $this->debug_output[] = "Subfolder is $subfolder";
            
            // we read the data from CSV into an array
            $fitData = $this->processFitFile($fitFile);
            $this->debug_output[] = "processFitFile returned " . (is_array($fitData) ? count($fitData) : 'NOT AN ARRAY') . " items\n";

            $path = $this->jsonOutputDir . $subfolder . basename($fitFile) . ".JSON";
            $new_folder = $this->jsonOutputDir . $subfolder;
            $this->debug_output[] = "creating folder $new_folder";
            if (!file_exists($new_folder)) {
                mkdir($new_folder, 0777, true);
            }            
            $this->debug_output[] = "Writing json file to $path";
            
            $final_data = array('data' => $fitData);
            
            $check = $this->writeJSON($final_data, $path);
            if ($check) {
                $divesData[] = $fitData;
            } else {
                echo "ERROR writing Dive data JSON to $path";
            }
        }
     
        return $divesData;
    }

    private function writeJSON($fitData, $filepath) {
        // Convert to JSON
        $jsonData = json_encode($fitData, JSON_PRETTY_PRINT);

        // Write to file
        if (file_put_contents($filepath, $jsonData)) {
            $this->debug_output[] = [
                'success' => true,
                'size' => filesize($filepath),
                'samples' => count($fitData)
            ];
            return true;
        } else {
            $this->debug_output[] = ['error' => "Failed to write JSON file: $filepath"];
            return false;
        }
    }
    
    private function findFitFilesRegex($directory) {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("Directory does not exist: $directory");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        // Filter files with .fit extension using regex
        $fitIterator = new RegexIterator($iterator, '/\.fit$/i');

        $fitFiles = [];
        foreach ($fitIterator as $file) {
            $fitFiles[] = $file->getRealPath();
        }

        return $fitFiles;
    }
    
    /**
     * Process a single FIT file and return dive data
     */
    public function processFitFile($fitFile) {
        $filename = basename($fitFile);
        $this->debug_output[] = "FIT File: $filename\n";
        
        // Determine CSV file path
        if ($this->csvOutputDir) {
            // Use separate CSV output directory
            if (!file_exists($this->csvOutputDir)) {
                mkdir($this->csvOutputDir, 0755, true);
            }
            $csvFile = $this->csvOutputDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.csv';
        } else {
            // Use same directory as FIT file
            $csvFile = str_replace('.fit', '.csv', $fitFile);
        }
        
        // Generate CSV if it doesn't exist
        if (!file_exists($csvFile)) {
            $this->debug_output[] = "  → CSV file not found, generating...\n";
            $result = $this->generateCsvFromFit($fitFile, $csvFile);
            
            if (!file_exists($csvFile)) {
                $this->debug_output[] = "  ❌ ERROR: Failed to generate CSV file: $result\n";
                return null;
            }
            $this->debug_output[] = "  ✅ CSV file generated successfully: " . basename($csvFile) . "\n";
        } else {
            $this->debug_output[] = "  ✅ CSV file already exists: " . basename($csvFile) . "\n";
        }
        
        // Read dive start time and GPS data from CSV
        $this->debug_output[] = "  → Reading dive start time and GPS data...\n";
        $diveData = $this->getDiveData($csvFile);
        if (!$diveData || !isset($diveData['start_time'])) {
            $this->debug_output[] = "  ❌ ERROR: Could not determine dive start time\n";
            return null;
        }
        
        $diveStartTime = $diveData['start_time'];
        $gpsData = $diveData['gps_data'] ?? null;
        
        $this->debug_output[] = "  ✅ Dive started at: " . $diveStartTime->format('Y-m-d H:i:s') . " (" . $this->timezone . ")\n";
        if ($gpsData) {
            $this->debug_output[] = "  ✅ GPS data found: Lat: " . $gpsData['latitude'] . ", Lon: " . $gpsData['longitude'] . "\n";
        } else {
            $this->debug_output[] = "  ⚠️ No GPS data found in CSV\n";
        }
        
        // Get depth data from CSV
        $this->debug_output[] = "  → Parsing depth data from CSV...\n";
        $fitData = $this->readFITData($csvFile);
        $fitDataCount = count($fitData);
        $this->debug_output[] = "  ✅ Found $fitDataCount data lines\n";
        
        if (empty($fitData)) {
            $this->debug_output[] = "  ❌ ERROR: No data found in CSV\n";
            return null;
        }
        
        // we get the end of the dive
        // clone the fit data:
        $fitData_clone = $fitData;
        unset($fitData_clone['units']);
        $end_time_timestamp = max(array_keys($fitData_clone));
        unset($fitData_clone);
        
        return [
            'start_time' => $diveStartTime,
            'end_time' => $this->convert_UTC_TS_to_array($end_time_timestamp),
            'depth_data' => $fitData,
            'gps_data' => $gpsData,
            'fit_file' => $filename,
            'csv_file' => basename($csvFile)
        ];
    }
    
    /**
     * Generate CSV from FIT file using Java tool
     */
    private function generateCsvFromFit($fitFile, $targetCsvFile) {
        // First generate CSV in the same directory as the FIT file
        $tempCsvFile = str_replace('.fit', '.csv', $fitFile);
        $command = "java -jar " . escapeshellarg($this->fitCsvToolPath) . " " . escapeshellarg($fitFile);
        
        $this->debug_output[] = "    Executing: $command\n";
        $returnCode = false;
        $output = false;
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->debug_output[] = "    ❌ FitCSVTool execution failed with return code: $returnCode\n";
            if (!empty($output)) {
                $this->debug_output[] = "    Output: " . implode("\n    ", $output) . "\n";
            }
            return false;
        }
        
        // If using separate CSV directory, move the generated CSV file
        if ($this->csvOutputDir && file_exists($tempCsvFile)) {
            if ($tempCsvFile !== $targetCsvFile) {
                $this->debug_output[] = "    Moving CSV file to output directory: " . basename($targetCsvFile) . "\n";
                rename($tempCsvFile, $targetCsvFile);
            }
        }
        
        return file_exists($targetCsvFile);
    }
    
    /**
     * Parse dive start time and GPS data from CSV file with FIT timestamp conversion
     */
    private function getDiveData($csvFile) {
        $handle = fopen($csvFile, 'r');
        if (!$handle) {
            $this->debug_output[] = "    ❌ Could not open CSV file: " . basename($csvFile) . "\n";
            return false;
        }
        
        $startTime = null;
        $gpsData = null;
        $lineCount = 0;
        $headers = [];
        
        $this->debug_output[] = "    Scanning CSV for start_time and GPS data...\n";
        
        while (($line = fgets($handle)) !== false) {
            $lineCount++;
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = str_getcsv($line);
            
            // Skip empty lines or malformed lines
            if (count($parts) < 3) {
                continue;
            }

            // Parse headers
            if ($parts[0] === 'Type' && $parts[1] === 'Local Number') {
                $headers = $parts;
                continue;
            }
            
            // Look for session data with start_time and GPS
            if ($parts[0] === 'Data' && $parts[2] === 'session') {
                $this->debug_output[] = "    Found session data at line $lineCount\n";
                
                $startTimeValue = null;
                $startPosLat = null;
                $startPosLon = null;
                
                // Parse the session data line
                for ($i = 3; $i < count($parts); $i += 3) {
                    if (!isset($parts[$i]) || !isset($parts[$i + 1])) continue;
                    
                    $field = $parts[$i];
                    $value = trim($parts[$i + 1], '"');
                    
                    if ($field === 'start_time' && is_numeric($value)) {
                        $startTimeValue = $value;
                    } elseif ($field === 'start_position_lat' && is_numeric($value)) {
                        $startPosLat = $value;
                    } elseif ($field === 'start_position_long' && is_numeric($value)) {
                        $startPosLon = $value;
                    }
                }
                
                // Process start time
                if ($startTimeValue) {
                    $unixTimestamp = (int)$startTimeValue + self::FIT_TIMESTAMP_OFFSET;
                    $startTime = $this->convert_UTC_TS_to_array($unixTimestamp);
                }
                
                // Process GPS data
                if ($startPosLat !== null && $startPosLon !== null) {
                    $gpsData = $this->convertSemicirclesToDMS($startPosLat, $startPosLon);
                    $this->debug_output[] = "    ✅ Found GPS data: Lat: $startPosLat, Lon: $startPosLon -> " . $gpsData['latitude'] . ", " . $gpsData['longitude'] . "\n";
                }
                
                if ($startTime) {
                    break;
                }
            }
        }
        
        fclose($handle);
        
        if (!$startTime) {
            $this->debug_output[] = "    ❌ No valid start time found in CSV after scanning $lineCount lines\n";
            $this->debug_output[] = "    Trying alternative parsing method...\n";
            $diveData = $this->getDiveDataAlternative($csvFile);
            if ($diveData) {
                $startTime = $diveData['start_time'];
                $gpsData = $diveData['gps_data'] ?? null;
            }
        }
        
        if (!$startTime) {
            return false;
        }
        
        return [
            'start_time' => $startTime,
            'gps_data' => $gpsData
        ];
    }
    
    /**
     * Convert semicircles to degrees, minutes, seconds format
     */
    private function convertSemicirclesToDMS($latSemicircles, $lonSemicircles) {
        // Semicircles to degrees conversion factor
        $semicirclesToDegrees = 180 / pow(2, 31);
        
        // Convert semicircles to decimal degrees
        $latDecimal = $latSemicircles * $semicirclesToDegrees;
        $lonDecimal = $lonSemicircles * $semicirclesToDegrees;
        
        // Convert decimal degrees to DMS format
        $latDMS = $this->decimalToDMS($latDecimal, true);
        $lonDMS = $this->decimalToDMS($lonDecimal, false);
        
        return [
            'latitude' => $latDMS,
            'longitude' => $lonDMS,
            'lat_decimal' => $latDecimal,
            'lon_decimal' => $lonDecimal
        ];
    }
    
    /**
     * Convert decimal degrees to DMS format (degrees, minutes, seconds)
     */
    private function decimalToDMS($decimal, $isLatitude) {
        $direction = $isLatitude ? ($decimal >= 0 ? 'N' : 'S') : ($decimal >= 0 ? 'E' : 'W');
        $abs_decimal = abs($decimal);
        
        $degrees = floor($abs_decimal);
        $minutesDecimal = ($abs_decimal - $degrees) * 60;
        $minutes = floor($minutesDecimal);
        $seconds = round(($minutesDecimal - $minutes) * 60, 5);
        
        // Format as "degrees,minutes.secondsSSS" (e.g., "8,30.58056S")
        return sprintf('%d,%02d.%05d%s', $degrees, $minutes, $seconds * 10000, $direction);
    }
    
    /**
     * Alternative method to parse dive data from CSV
     */
    private function getDiveDataAlternative($csvFile) {
        $content = file_get_contents($csvFile);
        if (!$content) {
            return false;
        }
        
        $startTime = null;
        $gpsData = null;
        
        // Look for start_time pattern
        if (preg_match('/start_time,"(\d+)"/', $content, $matches)) {
            $startTimeValue = $matches[1];
            $unixTimestamp = (int)$startTimeValue + self::FIT_TIMESTAMP_OFFSET;
            $startTime = DateTime::createFromFormat('U', $unixTimestamp, new DateTimeZone('UTC')); // Create as UTC first
            if ($startTime) {
                // Convert to configured timezone
                $startTime->setTimezone($this->timezoneObj);
                $this->debug_output[] = "    ✅ Alternative method found start_time: $startTimeValue -> UNIX: $unixTimestamp (" . $startTime->format('Y-m-d H:i:s e') . ")\n";
            }
        }
        
        // Look for GPS data
        if (preg_match('/start_position_lat,"([^"]+)"/', $content, $latMatches) && 
            preg_match('/start_position_long,"([^"]+)"/', $content, $lonMatches)) {
            $latSemicircles = $latMatches[1];
            $lonSemicircles = $lonMatches[1];
            
            if (is_numeric($latSemicircles) && is_numeric($lonSemicircles)) {
                $gpsData = $this->convertSemicirclesToDMS($latSemicircles, $lonSemicircles);
                $this->debug_output[] = "    ✅ Alternative method found GPS data: Lat: $latSemicircles, Lon: $lonSemicircles -> " . $gpsData['latitude'] . ", " . $gpsData['longitude'] . "\n";
            }
        }
        
        if (!$startTime) {
            $this->debug_output[] = "    ❌ Alternative method also failed to find start time\n";
            return false;
        }
        
        return [
            'start_time' => $startTime,
            'gps_data' => $gpsData
        ];
    }
    
    /**
     * Parse depth data from CSV file with FIT timestamp conversion
     */
    private function readFITData($csvFile) {
        $fit_data = [];
        $handle = fopen($csvFile, 'r');
        
        if (!$handle) {
            $this->debug_output[] = "    ❌ Could not open CSV file for depth parsing\n";
            return [];
        }

        $lineCount = 0;
        $records = 0;
        $last_timestamp = false;
        
        $this->debug_output[] = "    Parsing depth data from CSV...\n";
        
        $units = array();
        // this is the index key
        $key = false;
        
        while (($line = fgets($handle)) !== false) {
            $timestamp = null;
            $lineCount++;
            $line_fixed = trim($line);
            if (empty($line_fixed)) {
                continue;
            }
            
            $parts = str_getcsv($line);
            
            // we have always 3 fields, the name of the field, the data and the unit. 
            // if there is less than 3, there is an error.
            
            // Skip empty lines or malformed lines
            if ((count($parts) < 3) || ($parts[0] !== 'Data') ){
                continue;
            }

            // valid 'Message' column
            if ($parts[2] !== 'record' && $parts[2] !== 'tank_update') {
                continue;
            }
                
            $this_row = array();

            // Parse field-value pairs from the CSV row
            // Format: ...,Field_Name,"Value",Units,Field_Name2,"Value2",Units2,...
            
            // Iterate one line in steaps of 3 fields
            for ($i = 3; $i < count($parts); $i += 3) {
                if ($i + 2 < count($parts)) {
                    $field = $parts[$i];
                    $value = trim($parts[$i + 1], '"');
                    $unit = $parts[$i + 2];

                    // Handle timestamp
                    if ($field === 'timestamp') {
                        $unixTimestamp = (int)$value + self::FIT_TIMESTAMP_OFFSET;
                        $temp_timestamp = $unixTimestamp;

                        // Control the frequency of data
                        if ($last_timestamp && ($temp_timestamp - $last_timestamp) < $this->data_frequency) {
                            continue 2; // Skip to next CSV row
                        } else {
                            $timestamp = $temp_timestamp;
                            $last_timestamp = $timestamp;
                        }
                        continue;
                    }

                    // Skip invalid fields
                    if (!is_numeric($value) || $field == 'unknown') {
                        continue;
                    }

                    // Store units and capture required data fields
                    if (in_array($field, $this->required_data)) {
                        if (!isset($units[$field])) {
                            $units[$field] = array('unit' => $unit, 'key' => $this->incrementKey($key));
                        }
                        $field_key = $units[$field]['key'];
                        $this_row[$field_key] = $value;
                    }
                }
            }

            // Add row if we have timestamp and data
            if (isset($timestamp) && count($this_row) > 0) {
                if (isset($fitData[$timestamp])) {
                    $this_row = array_merge($this_row, $fitData[$timestamp]);
                }
                $fit_data[$timestamp] = $this_row;
            }

            // now we add a line to the final data
            if ($timestamp !== null && count($this_row) > 0) {
                if (isset($fit_data[$timestamp])) {
                    $this_row = array_merge($this_row, $fit_data[$timestamp]);
                }
                ksort($this_row);
                $fit_data[$timestamp] = $this_row;
                $records++;
            }
            $last_timestamp = $timestamp;
        }
        
        // let's attach units
        $fit_data['units'] = $units;
        
        fclose($handle);
        $this->debug_output[] = "    Found $records records in $lineCount CSV lines\n";
        
        // Sort by timestamp for easier searching
        ksort($fit_data);
        return $fit_data;
    }
    
    private function incrementKey(&$key) {
        if ($key === false) {
            $key = 'a';
        } else {
            // Convert to ASCII code, increment, then convert back to character
            $key = chr(ord($key) + 1);

            // If we go beyond 'z', wrap around to 'a'
            if ($key > 'z') {
                $key = 'a';
            }
        }
        return $key;
    }
    // convert a unix timestamp to the configured timezone and 
    private function convert_UTC_TS_to_array($timestamp) {
        $timeObj = DateTime::createFromFormat('U', $timestamp, new DateTimeZone('UTC')); // Create as UTC first
        if ($timeObj) {
            // Convert to configured timezone
            $timeObj->setTimezone($this->timezoneObj);
            $this->debug_output[] = "    ✅ converted timestamp: $timestamp (" . $timeObj->format('Y-m-d H:i:s e') . ")\n";
            return $timeObj;
        } else {
            return false;
        }
    }
}