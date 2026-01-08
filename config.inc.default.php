<?php
global $CONF;

$CONF = array(

// The path to your FitCVSTool.jar
// Get it here: https://developer.garmin.com/fit/download/
// no need to change this if the jar file in the same folder as this config file
'fitCsvToolPath' => './FitCSVTool.jar',

// The folder location where you have your original photos with they XMP files
// the tool will recurse into subfolders and add all files found
'photoFolder' => '/mnt/c/DCIM',
    
// what's the name of your valid RAW Files? If you also want to process JPG
// files, add the relevant extensions here. This is not case-sensitive.
// It still requires the XMP files to be present for those files
'file_extensions' => ['cr3'],    

// The folder where you downloaded your .FIT files to
// the tool will recurse into subfolders and add all files found
'fitFolder' => '/mnt/c/fitfiles',

// We need to store an intermediate CVS file. Choose a folder where they will be stored
// All files will be stored here, with the same name as the FIT Files, regardless of the 
// directory structure the FIT files were found in.
'csvOutputDir' => '/mnt/c/cvsfiles',

// if there is already a depth/altitude information in the file, do we overwrite it with the 
// data from the FIT File?
'update_existing_depth' => true,

// if there is already a GPS information in the file, do we overwrite it with the 
// data from the FIT File?
'update_existing_gps' => true, // Set to true to update XMP files that already have GPS info

// what's your timezone?
'timezone' => 'Asia/Hong_Kong', // Default timezone for all date/time operations

/** ---------------- JSON FILE GENERATION ------------------ **/
    
// Where do you want to store the JSON Files?
// All files will be stored here, with the same name as the FIT Files, regardless of the 
// directory structure the FIT files were found in.
'jsonOutputDir' => '/mnt/c/jsonfiles',    
    
// How many datapoints you want to collect into the JSON file?
// This is mainly for people who want to re-use the JSON files for other purposes
// but do not want them to be too big. 
// enter 10 for 1 every 10 seconds, 0 for all data
'data_frequency' => '10',

// what fields do you want to extract to JSON?
'required_data' => ['depth', 'temperature'],
    
/** ---------------- REPORT GENERATION ------------------ **/    

// Do you want to generate a detailes report in the end?
'generateReport' => true, // Set to true to generate CSV report

// what's the filename for the reports?
'reportFile' => './dive_photo_report.csv', // Path for the report
    
);