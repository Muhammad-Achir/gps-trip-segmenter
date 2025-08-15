<?php
// File input & output
$inputFile = 'points.csv';
$rejectLog = 'rejects.log';
$outputGeoJSON = 'trips.geojson';

// Clear old log
file_put_contents($rejectLog, "");

// Read CSV
if (!file_exists($inputFile)) {
    die("File $inputFile not found.\n");
}

$handle = fopen($inputFile, 'r');
if ($handle === false) {
    die("Failed to open CSV file.\n");
}

// Read header
$header = fgetcsv($handle);
$data = [];
$rejects = [];

// Parse CSV rows
while (($row = fgetcsv($handle)) !== false) {
    [$device_id, $lat, $lon, $timestamp] = $row;

    // Coordinate validation
    $latValid = is_numeric($lat) && $lat >= -90 && $lat <= 90;
    $lonValid = is_numeric($lon) && $lon >= -180 && $lon <= 180;

    // Timestamp validation
    try {
        new DateTime($timestamp);
        $timeValid = true;
    } catch (Exception $e) {
        $timeValid = false;
    }

    if ($latValid && $lonValid && $timeValid) {
        $data[] = [
            'device_id' => $device_id,
            'lat' => (float)$lat,
            'lon' => (float)$lon,
            'timestamp' => $timestamp
        ];
    } else {
        $rejects[] = implode(',', $row);
    }
}
fclose($handle);

// Sort data by timestamp
usort($data, function ($a, $b) {
    return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
});

// Save rejected rows
if (!empty($rejects)) {
    file_put_contents($rejectLog, implode(PHP_EOL, $rejects));
}

// Debug output
echo "Valid data: " . count($data) . " rows\n";
echo "Invalid data: " . count($rejects) . " rows (saved to $rejectLog)\n";

// Haversine formula to calculate distance in km
function haversine($lat1, $lon1, $lat2, $lon2)
{
    $R = 6371;
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;

    $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $R * $c;
}

// Segment trips based on time gap (>25 min) or distance (>2 km)
$trips = [];
$tripIndex = 1;
$currentTrip = [];
$lastPoint = null;

foreach ($data as $point) {
    if ($lastPoint) {
        $timeDiff = (strtotime($point['timestamp']) - strtotime($lastPoint['timestamp'])) / 60;
        $dist = haversine($lastPoint['lat'], $lastPoint['lon'], $point['lat'], $point['lon']);

        if ($timeDiff > 25 || $dist > 2) {
            if (!empty($currentTrip)) {
                $trips["trip_$tripIndex"] = $currentTrip;
                $tripIndex++;
                $currentTrip = [];
            }
        }
    }
    $currentTrip[] = $point;
    $lastPoint = $point;
}

if (!empty($currentTrip)) {
    $trips["trip_$tripIndex"] = $currentTrip;
}

echo "Total trips: " . count($trips) . "\n";

// Function to generate distinct colors using HSL
function hslColor($i, $total)
{
    $hue = ($i * 360 / $total) % 360;
    return sprintf("#%02x%02x%02x", ...hslToRgb($hue / 360, 0.7, 0.5));
}

// Convert HSL to RGB
function hslToRgb($h, $s, $l)
{
    $r = $l;
    $g = $l;
    $b = $l;
    if ($s != 0) {
        $q = ($l < 0.5) ? ($l * (1 + $s)) : ($l + $s - $l * $s);
        $p = 2 * $l - $q;
        $r = hueToRgb($p, $q, $h + 1 / 3);
        $g = hueToRgb($p, $q, $h);
        $b = hueToRgb($p, $q, $h - 1 / 3);
    }
    return [round($r * 255), round($g * 255), round($b * 255)];
}

function hueToRgb($p, $q, $t)
{
    if ($t < 0) $t += 1;
    if ($t > 1) $t -= 1;
    if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
    if ($t < 1 / 2) return $q;
    if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
    return $p;
}

// Generate GeoJSON
$features = [];
$i = 0;
foreach ($trips as $tripId => $points) {
    $i++;
    $totalDistance = 0;
    $maxSpeed = 0;

    for ($j = 1; $j < count($points); $j++) {
        $dist = haversine($points[$j - 1]['lat'], $points[$j - 1]['lon'], $points[$j]['lat'], $points[$j]['lon']);
        $totalDistance += $dist;
        $timeDiffHrs = (strtotime($points[$j]['timestamp']) - strtotime($points[$j - 1]['timestamp'])) / 3600;
        if ($timeDiffHrs > 0) {
            $speed = $dist / $timeDiffHrs;
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }
        }
    }

    $durationMin = (strtotime(end($points)['timestamp']) - strtotime($points[0]['timestamp'])) / 60;
    $avgSpeed = ($durationMin > 0) ? ($totalDistance / ($durationMin / 60)) : 0;

    $color = hslColor($i, count($trips));

    $features[] = [
        "type" => "Feature",
        "properties" => [
            "trip_id" => $tripId,
            "points" => count($points),
            "total_distance_km" => round($totalDistance, 3),
            "duration_min" => round($durationMin, 1),
            "avg_speed_kmh" => round($avgSpeed, 2),
            "max_speed_kmh" => round($maxSpeed, 2),
            "stroke" => $color,
            "stroke-width" => 3,
            "stroke-opacity" => 1
        ],
        "geometry" => [
            "type" => "LineString",
            "coordinates" => array_map(fn($p) => [$p['lon'], $p['lat']], $points)
        ]
    ];
}

$geojson = [
    "type" => "FeatureCollection",
    "features" => $features
];

file_put_contents($outputGeoJSON, json_encode($geojson, JSON_PRETTY_PRINT));

echo "GeoJSON saved to $outputGeoJSON\n";
