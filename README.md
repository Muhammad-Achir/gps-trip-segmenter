# GPS Trip Segmenter

## Description
This PHP script processes GPS points from a CSV file, validates the data, sorts it by timestamp, segments it into trips based on time and distance thresholds, calculates trip statistics, and outputs the results as a GeoJSON file. Invalid rows are logged separately.

## Features
- Cleans input CSV by discarding invalid coordinates or timestamps (`rejects.log`).
- Sorts GPS points by timestamp.
- Splits points into trips:
  - Time gap > 25 minutes
  - Straight-line distance jump > 2 km
- Computes for each trip:
  - Total distance (km)
  - Duration (minutes)
  - Average speed (km/h)
  - Maximum speed (km/h)
- Outputs trips as GeoJSON `FeatureCollection` with each trip as a `LineString` and unique color.
- Pure PHP 8 implementation (no external libraries or APIs).
- Fast execution (<1 minute for typical datasets).

## Requirements
- PHP 8.x installed

## Files
- `process_gps.php` – main PHP script
- `points.csv` – input CSV file (format: device_id,lat,lon,timestamp)
- `rejects.log` – automatically generated file for invalid rows
- `trips.geojson` – output file containing trip geometries and statistics

## Usage
1. Place your CSV file named `points.csv` in the same directory as the script.
2. Run the script:

```bash
php process_gps.php
