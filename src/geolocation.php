<?php

//**************************************************************
//    Geolocation functions for extracting and processing 
//    GPS EXIF data from photos
//**************************************************************

/**
 * Extract GPS coordinates from a photo's EXIF data
 * 
 * @param string $photoPath Full path to the photo file
 * @return array|null Returns ['latitude' => float, 'longitude' => float] or null if no GPS data
 */
function extractGpsCoordinates($photoPath) {
    if (!function_exists('exif_read_data') || !file_exists($photoPath)) {
        return null;
    }
    
    // Read EXIF data, suppressing warnings for invalid files
    $exif = @exif_read_data($photoPath, 'GPS');
    
    if (empty($exif) || !isset($exif['GPSLatitude']) || !isset($exif['GPSLongitude'])) {
        return null;
    }
    
    // Convert GPS coordinates from EXIF format to decimal
    $latitude = gpsExifToDecimal(
        $exif['GPSLatitude'],
        isset($exif['GPSLatitudeRef']) ? $exif['GPSLatitudeRef'] : 'N'
    );
    
    $longitude = gpsExifToDecimal(
        $exif['GPSLongitude'],
        isset($exif['GPSLongitudeRef']) ? $exif['GPSLongitudeRef'] : 'E'
    );
    
    if ($latitude === null || $longitude === null) {
        return null;
    }
    
    return [
        'latitude' => $latitude,
        'longitude' => $longitude
    ];
}

/**
 * Convert EXIF GPS coordinate format to decimal degrees
 * 
 * @param array $coordinate The GPS coordinate array from EXIF [degrees, minutes, seconds]
 * @param string $hemisphere The hemisphere reference (N, S, E, W)
 * @return float|null The decimal degree value or null on error
 */
function gpsExifToDecimal($coordinate, $hemisphere) {
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    
    // Each element is typically in format "num/denom"
    $degrees = gpsExifFractionToFloat($coordinate[0]);
    $minutes = gpsExifFractionToFloat($coordinate[1]);
    $seconds = gpsExifFractionToFloat($coordinate[2]);
    
    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }
    
    // Convert to decimal degrees
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    
    // Apply hemisphere sign
    if ($hemisphere === 'S' || $hemisphere === 'W') {
        $decimal = -$decimal;
    }
    
    return round($decimal, 6);
}

/**
 * Convert EXIF GPS fraction string to float
 * 
 * @param mixed $fraction The fraction value (e.g., "48/1" or numeric)
 * @return float|null The float value or null on error
 */
function gpsExifFractionToFloat($fraction) {
    if (is_numeric($fraction)) {
        return (float)$fraction;
    }
    
    if (is_string($fraction) && strpos($fraction, '/') !== false) {
        $parts = explode('/', $fraction);
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
            return (float)$parts[0] / (float)$parts[1];
        }
    }
    
    return null;
}

/**
 * Reverse geocode GPS coordinates to get location details using OpenStreetMap Nominatim API
 * 
 * @param float $latitude The latitude in decimal degrees
 * @param float $longitude The longitude in decimal degrees
 * @return array Returns ['country' => string|null, 'village' => string|null, 'town' => string|null, 'city' => string|null]
 */
function reverseGeocode($latitude, $longitude) {
    $result = [
        'country' => null,
        'village' => null,
        'town' => null,
        'city' => null
    ];
    
    // OpenStreetMap Nominatim API endpoint (free, no API key required)
    // Note: Rate limit is 1 request per second
    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?format=json&lat=%s&lon=%s&zoom=13&addressdetails=1',
        urlencode($latitude),
        urlencode($longitude)
    );
    
    // Set up context with User-Agent (required by Nominatim)
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: SynologyPHPSimplePhotoSlideshow/1.0',
            'timeout' => 10
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        error_log(json_encode([
            'log' => 'reverseGeocode',
            'status' => 'error',
            'message' => 'Failed to fetch from Nominatim API',
            'latitude' => $latitude,
            'longitude' => $longitude
        ]));
        return $result;
    }
    
    $data = json_decode($response, true);
    
    if ($data === null || !isset($data['address'])) {
        return $result;
    }
    
    $address = $data['address'];
    
    // Extract country
    if (isset($address['country'])) {
        $result['country'] = $address['country'];
    }
    
    // Extract village, town, and city separately
    if (isset($address['village']) && !empty($address['village'])) {
        $result['village'] = $address['village'];
    }
    
    if (isset($address['town']) && !empty($address['town'])) {
        $result['town'] = $address['town'];
    }
    
    if (isset($address['city']) && !empty($address['city'])) {
        $result['city'] = $address['city'];
    }
    
    // If none of the above are set, try fallback fields for city
    if ($result['village'] === null && $result['town'] === null && $result['city'] === null) {
        $fallbackFields = ['municipality', 'county', 'state_district'];
        foreach ($fallbackFields as $field) {
            if (isset($address[$field]) && !empty($address[$field])) {
                $result['city'] = $address[$field];
                break;
            }
        }
    }
    
    return $result;
}

/**
 * Process a single photo to extract and geocode its location
 * 
 * @param string $photoPath Full path to the photo file
 * @return array Returns location data array with gps_lat, gps_lon, country, village, town, city fields
 */
function processPhotoGeolocation($photoPath) {
    $locationData = [
        'gps_lat' => null,
        'gps_lon' => null,
        'country' => null,
        'village' => null,
        'town' => null,
        'city' => null,
        'geocode_status' => 'not_processed'
    ];
    
    // Extract GPS coordinates
    $gpsCoords = extractGpsCoordinates($photoPath);
    
    if ($gpsCoords === null) {
        $locationData['geocode_status'] = 'no_gps_data';
        return $locationData;
    }
    
    $locationData['gps_lat'] = $gpsCoords['latitude'];
    $locationData['gps_lon'] = $gpsCoords['longitude'];
    
    // Reverse geocode to get country and location details
    $geocodeResult = reverseGeocode($gpsCoords['latitude'], $gpsCoords['longitude']);
    
    $locationData['country'] = $geocodeResult['country'];
    $locationData['village'] = $geocodeResult['village'];
    $locationData['town'] = $geocodeResult['town'];
    $locationData['city'] = $geocodeResult['city'];
    $locationData['geocode_status'] = 'completed';
    $locationData['geocode_timestamp'] = time();
    
    return $locationData;
}

/**
 * Update the picture index file with geolocation data for photos that don't have it
 * This is designed to be called asynchronously to avoid blocking the main slideshow
 * 
 * @param string $indexFilePath Path to the folder picture index file
 * @param int $batchSize Maximum number of photos to process in one call (for rate limiting)
 * @param int $delayBetweenRequests Delay in microseconds between API requests (default 1.1 seconds)
 * @return array Processing statistics
 */
function updateIndexWithGeolocation($indexFilePath, $batchSize = 10, $delayBetweenRequests = 1100000) {
    $stats = [
        'processed' => 0,
        'skipped' => 0,
        'errors' => 0,
        'no_gps' => 0,
        'already_geocoded' => 0
    ];
    
    if (!file_exists($indexFilePath)) {
        error_log(json_encode([
            'log' => 'updateIndexWithGeolocation',
            'status' => 'error',
            'message' => 'Index file not found',
            'file' => $indexFilePath
        ]));
        return $stats;
    }
    
    $indexData = file_get_contents($indexFilePath);
    $index = json_decode($indexData, true);
    
    if ($index === null) {
        error_log(json_encode([
            'log' => 'updateIndexWithGeolocation',
            'status' => 'error',
            'message' => 'Invalid JSON in index file',
            'file' => $indexFilePath
        ]));
        return $stats;
    }
    
    $processedCount = 0;
    $indexModified = false;
    
    foreach ($index as $photoPath => &$photoData) {
        // Skip if already geocoded AND has the new village/town fields (or has no GPS data)
        if (isset($photoData['geocode_status'])) {
            if ($photoData['geocode_status'] === 'no_gps_data') {
                $stats['already_geocoded']++;
                continue;
            }
            if ($photoData['geocode_status'] === 'completed' && array_key_exists('village', $photoData)) {
                $stats['already_geocoded']++;
                continue;
            }
            // If completed but missing village field, we need to re-geocode
        }
        
        // Skip if we've reached the batch limit
        if ($processedCount >= $batchSize) {
            break;
        }
        
        // Check if file still exists
        if (!file_exists($photoPath)) {
            $stats['skipped']++;
            continue;
        }
        
        // Process the photo
        $locationData = processPhotoGeolocation($photoPath);
        
        // Merge location data into photo data
        $photoData = array_merge($photoData, $locationData);
        $indexModified = true;
        
        if ($locationData['geocode_status'] === 'no_gps_data') {
            $stats['no_gps']++;
        } else if ($locationData['geocode_status'] === 'completed') {
            $stats['processed']++;
        } else {
            $stats['errors']++;
        }
        
        $processedCount++;
        
        // Rate limiting - wait between API requests (only if we actually made an API call)
        if ($locationData['gps_lat'] !== null && $processedCount < $batchSize) {
            usleep($delayBetweenRequests);
        }
    }
    
    // Save the updated index if modified
    if ($indexModified) {
        file_put_contents($indexFilePath, json_encode($index, JSON_PRETTY_PRINT));
        
        error_log(json_encode([
            'log' => 'updateIndexWithGeolocation',
            'status' => 'success',
            'file' => basename($indexFilePath),
            'stats' => $stats
        ]));
    }
    
    return $stats;
}

/**
 * Find all folder picture index files in the temp directory
 * 
 * @param string $tempDir Path to the temp directory
 * @return array List of index file paths
 */
function findFolderPictureIndexFiles($tempDir) {
    $indexFiles = [];
    
    $pattern = $tempDir . DIRECTORY_SEPARATOR . 'folderpics-*-index.json';
    $files = glob($pattern);
    
    if ($files !== false) {
        $indexFiles = $files;
    }
    
    return $indexFiles;
}

/**
 * Get geolocation processing status for an index file
 * 
 * @param string $indexFilePath Path to the index file
 * @return array Status information
 */
function getGeolocationStatus($indexFilePath) {
    $status = [
        'total_photos' => 0,
        'geocoded' => 0,
        'no_gps' => 0,
        'pending' => 0,
        'percent_complete' => 0
    ];
    
    if (!file_exists($indexFilePath)) {
        return $status;
    }
    
    $indexData = file_get_contents($indexFilePath);
    $index = json_decode($indexData, true);
    
    if ($index === null) {
        return $status;
    }
    
    $status['total_photos'] = count($index);
    
    foreach ($index as $photoPath => $photoData) {
        if (isset($photoData['geocode_status'])) {
            if ($photoData['geocode_status'] === 'completed') {
                $status['geocoded']++;
            } else if ($photoData['geocode_status'] === 'no_gps_data') {
                $status['no_gps']++;
            }
        } else {
            $status['pending']++;
        }
    }
    
    if ($status['total_photos'] > 0) {
        $processed = $status['geocoded'] + $status['no_gps'];
        $status['percent_complete'] = round(($processed / $status['total_photos']) * 100, 1);
    }
    
    return $status;
}

?>
