<?php
global $CONF;

$CONF = array(

// The path to your FitCVSTool.jar
// Get it here: https://developer.garmin.com/fit/download/
'fitCsvToolPath' => './FitCSVTool.jar',

// The folder location where you have your original photos with they XMP files
'photoFolder' => '/mnt/c/DCIM',

// The folder where you downloaded your .FIT files to
'fitFolder' => '/mnt/c/fitfiles',

// We need to store an intermediate CVS file. Choose a folder where they will be stored
'csvOutputDir' => '/mnt/c/cvsfiles',

// How many datapoints you want to collect into the JSON file? If there are too many, 
// enter 10 for 1 every 10 seconds, 0 for all data
'data_frequency' => '10',

// what fields do you want to extract to JSON?
'required_data' => ['depth', 'temperature'],

// Where do you want to store the JSON Files?
'jsonOutputDir' => '/mnt/c/jsonfiles',

// Do you want to generate a detailes report in the ende?
'generateReport' => true, // Set to true to generate CSV report

// what's the filename for the reports?
'reportFile' => './dive_photo_report.csv', // Path for the report

// what's the name of your valid RAW Files?
'file_extensions' => ['cr3'],

// if there is already a depth/altitude information in the file, do we overwrite it with the 
// data from the FIT File?
'update_existing_depth' => true,

// if there is already a GPS information in the file, do we overwrite it with the 
// data from the FIT File?
'update_existing_gps' => true, // Set to true to update XMP files that already have GPS info

// what's your timezone?
'timezone' => 'Asia/Hong_Kong', // Default timezone for all date/time operations

);