# garmin_dive_imgsync
syncronize garmin dive data into Lightroom images

## what this does:
This script helps you write the GPS and depth data from your garmin dive watch into
the photo metadata that you took during a dive, fully automated. 
It also creates JSON files from your dive for further data processing.
JPGs exported from RAW files will include the metadata stored in the file

## installation
* download the code here
* download the Garmin FitCVSTool.jar from https://developer.garmin.com/fit/download/
* save the Garmin .jar in the same folder as the code here.
* Windows Linux Subsystem (see here: https://learn.microsoft.com/en-us/windows/wsl/install)
* rename the config.inc.default.php to config.inc.php
* create a dedicated folder for FIT, CSV and JSON files (can be the same or 3 different folders)
* edit the file to match your environment

## usage
* download the garmin FIT files for your dives from Garmin connect:
  - Open the dive in the Garmin connect website, click on the cogwheel icon on the top right
  - Click on "Export File"
  - unzip all FIT files and store them in the above configured folder (as many FIT files as you like)
* configure that folder in the above config.inc.php
* in Lightroom, find the folder with your dives and save the meta data to XMP files by selecting all the photos, then in the menu select "Metadata" - "Save metadata to file"
* Run the script under linux with the command `php ./index.php`. Then this happens:
  - The script will read the metadata for your photos
  - The script will convert all the FIT files into CSV files, then extract only the relevant dive information into JSON files.
  - The script will match for every dive the photos taken during that dive and write the GPS and depth information back into the XMP files
* Once the script has finished, to back into lightroom and select "Metadata" - "Read metadata from files".
* check the metadata and the depth and GPS information in your file info.
* done

