<?php

//**************************************************************
//    Common functions
//**************************************************************

// Include geolocation functions
require_once __DIR__ . DIRECTORY_SEPARATOR . 'geolocation.php';

function loadConfigWithCaching($configFile, $playlistsIndexFile) {
    $needsReload = false;
    
    // Check if this is a new session (no config cached)
    if (!isset($_SESSION['config']) || !isset($_SESSION['config_file_mtime'])) {
        $needsReload = true;
        $logReason = 'new_session';
    } else {
        // Check if config file has been modified since last load
        $currentMtime = filemtime($configFile);
        if ($currentMtime !== $_SESSION['config_file_mtime']) {
            $needsReload = true;
            $logReason = 'file_modified';
        }
    }
    
    // Load config only if needed
    if ($needsReload) {
        $config = configGet($configFile, $playlistsIndexFile);
        
        // Cache the config and file modification time in session
        $_SESSION['config'] = $config;
        $_SESSION['config_file_mtime'] = filemtime($configFile);
        
        $logObj = [
            'log' => 'configReload',
            'reason' => $logReason,
            'mtime' => $_SESSION['config_file_mtime'],
            'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown'
        ];
        error_log(json_encode($logObj));
    } else {
        // Use cached config
        $config = $_SESSION['config'];
    }
    
    return $config;
}

function configGet ($configFile, $playlistsIndexFile) {
    $config_data = file_get_contents($configFile);
    $config = json_decode($config_data, true);
    
    if ($config === null) {
        return null;
    }
    
    // Load or create the playlists index
    $index = [];
    if (file_exists($playlistsIndexFile)) {
        $indexData = file_get_contents($playlistsIndexFile);
        $index = json_decode($indexData, true);
        if ($index === null) {
            $index = [];
        }
    }
    
    // Build a list of current playlist identifiers from config
    $currentPlaylists = [];
    if (isset($config['playlist']) && is_array($config['playlist'])) {
        foreach ($config['playlist'] as $playlist) {
            // Use path as unique identifier for playlists
            if (isset($playlist['path'])) {
                $playlistId = $playlist['path'];
                $currentPlaylists[$playlistId] = [
                    'name' => isset($playlist['name']) ? $playlist['name'] : basename($playlist['path']),
                    'path' => $playlist['path']
                ];
            }
        }
    }
    
    // Update index: add new playlists, remove non-existing ones
    $updatedIndex = [];
    foreach ($currentPlaylists as $playlistId => $playlistInfo) {
        $updatedIndex[$playlistId] = [
            'name' => $playlistInfo['name'],
            'root_path' => $playlistInfo['path'],
            'play_count' => isset($index[$playlistId]) ? $index[$playlistId]['play_count'] : 0
        ];
    }
    
    // Save the updated index back to file
    file_put_contents($playlistsIndexFile, json_encode($updatedIndex, JSON_PRETTY_PRINT));
    
    // Create or update individual playlist folder indices
    $baseDir = dirname($playlistsIndexFile);
    // Ensure temp directory exists
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }
    $playlistFolderIndices = [];
    if (isset($config['playlist']) && is_array($config['playlist'])) {
        foreach ($config['playlist'] as $index => $playlist) {
            $folderIndexInfo = createOrUpdatePlaylistFolderIndex($playlist, $index, $baseDir);
            $playlistFolderIndices[$index] = $folderIndexInfo;
        }
    }
    
    // Add the index to the config for use by other parts of the application
    $config['playlists_index'] = $updatedIndex;
    $config['playlist_folder_indices'] = $playlistFolderIndices;
    
    return $config;
}

function playlistIncrementPlayCount($playlistPath, $playlistsIndexFile) {
    if (file_exists($playlistsIndexFile)) {
        $indexData = file_get_contents($playlistsIndexFile);
        $index = json_decode($indexData, true);
        
        if ($index && isset($index[$playlistPath])) {
            $index[$playlistPath]['play_count']++;
            file_put_contents($playlistsIndexFile, json_encode($index, JSON_PRETTY_PRINT));
            
            $logObj = [ 'log' => 'playCountIncrement',
                'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown', 
                'playlist' => $playlistPath,
                'new_count' => $index[$playlistPath]['play_count']];
            error_log(json_encode($logObj));
        }
    }
}

function playlistPick ($PlaylistMap, $Playlist, $playlistsIndexFile) {
    $total = count($PlaylistMap);
    $rd = random_int(0, $total-1);
    $val = array_values($PlaylistMap)[$rd];
    
    $selectedPlaylist = $Playlist[$val];
    
    // Increment play count for the selected playlist
    if (isset($selectedPlaylist['path'])) {
        playlistIncrementPlayCount($selectedPlaylist['path'], $playlistsIndexFile);
    }
    
    $logObj = [ 'log' => 'playlistPick',
        'scanID' => $_SESSION['playlist-scanid'], 
        'rand' => $rd,
        'idx to use' => $val,
        'playlist_path' => $selectedPlaylist['path'] ?? 'unknown'];
    error_log(json_encode($logObj));
    return $selectedPlaylist;
}

function playlistItemPhotos($plitem, $photoExt, &$photoFolder, $excludeText = '')
{
    global $playlistsIndexFile;
    $baseDir = $playlistsIndexFile ? dirname($playlistsIndexFile) : '';
    
    // Ensure temp directory exists
    if ($baseDir && !is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    if (!isset($plitem['scan-sub-folders']) || $plitem['scan-sub-folders'] == false) {
        $photoFolder = $plitem['path'];
        
        // Create or update folder picture index and return the picture data
        if ($playlistsIndexFile && $baseDir) {
            $pictureIndex = playlistItemPhotosNextBatch($photoFolder, $photoExt, $baseDir, $excludeText);
            return $pictureIndex ? $pictureIndex['pictures'] : [];
        }
        
        // Fallback to simple file list if no index system available
        $photos = [];
        dirContentsGet($plitem['path'], '/\.' . $photoExt . '$/i', $photos, $excludeText);
        $photoData = [];
        foreach ($photos as $photo) {
            $photoData[$photo] = ['play_count' => 0];
        }
        return $photoData;
    } else {
        // Load playlist folder index to get play counts
        $playlistName = isset($plitem['name']) ? $plitem['name'] : basename($plitem['path']);
        $sanitizedName = sanitizePlaylistName($playlistName);
        $indexFileName = "playlist-{$sanitizedName}-index.json";
        $indexFilePath = $baseDir . DIRECTORY_SEPARATOR . $indexFileName;
        
        $folderPlayCounts = [];
        if (file_exists($indexFilePath)) {
            $indexData = file_get_contents($indexFilePath);
            $index = json_decode($indexData, true);
            if ($index) {
                $folderPlayCounts = $index;
            }
        }
        
        // Get folders from the index (which already contains current folders)
        $dirs = array_keys($folderPlayCounts);
        
        // If index is empty (first run), fall back to filesystem scan
        if (empty($dirs)) {
            $dirs = dirSubFoldersGet($plitem['path']);
        }
        
        if (empty($dirs)) {
            return [];
        }
        
        // Find maximum play count
        $maxPlayCount = 0;
        foreach ($dirs as $dir) {
            $playCount = isset($folderPlayCounts[$dir]) ? $folderPlayCounts[$dir]['play_count'] : 0;
            $maxPlayCount = max($maxPlayCount, $playCount);
        }
        
        // Filter folders to those with play count less than maximum
        $eligibleFolders = [];
        foreach ($dirs as $dir) {
            $playCount = isset($folderPlayCounts[$dir]) ? $folderPlayCounts[$dir]['play_count'] : 0;
            if ($playCount < $maxPlayCount) {
                $eligibleFolders[] = $dir;
            }
        }
        
        // If no folders have lower play count (all equal), use all folders
        if (empty($eligibleFolders)) {
            $eligibleFolders = $dirs;
        }
        
        // Randomly select from eligible folders
        $selectedFolder = $eligibleFolders[random_int(0, count($eligibleFolders) - 1)];
        $photoFolder = $selectedFolder;
        
        $logObj = [
            'log' => 'smartFolderSelection',
            'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown',
            'playlist_name' => $playlistName,
            'total_folders' => count($dirs),
            'eligible_folders' => count($eligibleFolders),
            'max_play_count' => $maxPlayCount,
            'selected_folder' => basename($selectedFolder)
        ];
        error_log(json_encode($logObj));
        
        // Increment folder play count
        if ($playlistsIndexFile && $baseDir) {
            incrementPlaylistFolderCount($plitem, $photoFolder, $baseDir);
        }
        
        // Create or update folder picture index for the selected folder and return picture data
        if ($playlistsIndexFile && $baseDir) {
            $pictureIndex = playlistItemPhotosNextBatch($selectedFolder, $photoExt, $baseDir, $excludeText);
            return $pictureIndex ? $pictureIndex['pictures'] : [];
        }
        
        // Fallback to simple file list if no index system available
        $photos = [];
        dirContentsGet($selectedFolder, '/\.' . $photoExt . '$/i', $photos, $excludeText);
        $photoData = [];
        foreach ($photos as $photo) {
            $photoData[$photo] = ['play_count' => 0];
        }
        return $photoData;
    }
}

function createOrUpdateFolderPictureIndex($folderPath, $photoExt, $baseDir, $excludeText = '') {
    // Get the folder's GUID from the playlist folder index
    $folderGuid = getFolderGuid($folderPath, $baseDir);
    if (!$folderGuid) {
        // If no GUID found, we can't create a picture index
        return null;
    }
    
    $indexFileName = "folderpics-{$folderGuid}-index.json";
    $indexFilePath = $baseDir . DIRECTORY_SEPARATOR . $indexFileName;
    
    // Get current pictures in the folder, excluding any with excludeText in the path
    $currentPictures = [];
    dirContentsGet($folderPath, '/\.' . $photoExt . '$/i', $currentPictures, $excludeText);
    
    // Load existing index if it exists
    $existingIndex = [];
    if (file_exists($indexFilePath)) {
        $indexData = file_get_contents($indexFilePath);
        $existingIndex = json_decode($indexData, true);
        if ($existingIndex === null) {
            $existingIndex = [];
        }
    }
    
    // Check if folder contents have changed
    $existingPictures = array_keys($existingIndex);
    $addedPictures = array_diff($currentPictures, $existingPictures);
    $removedPictures = array_diff($existingPictures, $currentPictures);
    $hasChanges = !empty($addedPictures) || !empty($removedPictures);
    
    // If this is the first time creating the index, don't consider it as "changes"
    $isFirstTime = empty($existingIndex);
    if ($isFirstTime) {
        $hasChanges = false;
    }
    
    // Build updated index, preserving geolocation data for existing pictures
    $updatedIndex = [];
    $needsGeolocation = false;
    foreach ($currentPictures as $picture) {
        if ($hasChanges) {
            // If there are changes, reset play counts to 0 but preserve geolocation data
            $updatedIndex[$picture] = ['play_count' => 0];
            // Preserve geolocation data if it exists for this picture
            if (isset($existingIndex[$picture])) {
                $geoFields = ['gps_lat', 'gps_lon', 'country', 'village', 'town', 'city', 'geocode_status', 'geocode_timestamp'];
                foreach ($geoFields as $field) {
                    if (isset($existingIndex[$picture][$field])) {
                        $updatedIndex[$picture][$field] = $existingIndex[$picture][$field];
                    }
                }
            }
        } else {
            // No changes, preserve all existing data including geolocation
            $updatedIndex[$picture] = isset($existingIndex[$picture]) 
                ? $existingIndex[$picture] 
                : ['play_count' => 0];
        }
        
        // Check if this picture needs geolocation processing
        if (!isset($updatedIndex[$picture]['geocode_status'])) {
            $needsGeolocation = true;
        }
    }
    
    // Save the updated index
    file_put_contents($indexFilePath, json_encode($updatedIndex, JSON_PRETTY_PRINT));
    
    // Trigger async geolocation processing if needed
    if ($needsGeolocation || $isFirstTime || !empty($addedPictures)) {
        triggerAsyncGeolocationProcessing($indexFilePath, $batchSize=50);
    }
    
    $logObj = [
        'log' => 'folderPictureIndex',
        'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown',
        'folder_path' => basename($folderPath),
        'folder_guid' => $folderGuid,
        'index_file' => $indexFileName,
        'picture_count' => count($updatedIndex),
        'changes_detected' => $hasChanges,
        'added_pictures' => count($addedPictures),
        'removed_pictures' => count($removedPictures),
        'needs_geolocation' => $needsGeolocation
    ];
    error_log(json_encode($logObj));
    
    return [
        'file_path' => $indexFilePath,
        'file_name' => $indexFileName,
        'picture_count' => count($updatedIndex),
        'changes_detected' => $hasChanges,
        'pictures' => $updatedIndex
    ];
}

function playlistItemPhotosNextBatch($folderPath, $photoExt, $baseDir, $excludeText = '') {
    // Get the full picture index data
    $pictureIndexResult = createOrUpdateFolderPictureIndex($folderPath, $photoExt, $baseDir, $excludeText);
    
    if (!$pictureIndexResult || !isset($pictureIndexResult['pictures'])) {
        return $pictureIndexResult;
    }
    
    $pictures = $pictureIndexResult['pictures'];
    
    if (empty($pictures)) {
        return $pictureIndexResult;
    }
    
    // Find maximum play count
    $maxPlayCount = 0;
    foreach ($pictures as $picturePath => $pictureData) {
        $playCount = isset($pictureData['play_count']) ? $pictureData['play_count'] : 0;
        $maxPlayCount = max($maxPlayCount, $playCount);
    }
    
    // Filter out pictures with maximum play count
    $eligiblePictures = [];
    foreach ($pictures as $picturePath => $pictureData) {
        $playCount = isset($pictureData['play_count']) ? $pictureData['play_count'] : 0;
        if ($playCount < $maxPlayCount) {
            $eligiblePictures[$picturePath] = $pictureData;
        }
    }
    
    // If no pictures have lower play count (all equal), use all pictures
    if (empty($eligiblePictures)) {
        $eligiblePictures = $pictures;
    }
    
    // Update the result with filtered pictures
    $pictureIndexResult['pictures'] = $eligiblePictures;
    $pictureIndexResult['filtered_picture_count'] = count($eligiblePictures);
    $pictureIndexResult['max_play_count'] = $maxPlayCount;
    $pictureIndexResult['smart_filtering_applied'] = count($eligiblePictures) < count($pictures);
    
    return $pictureIndexResult;
}

function getFolderGuid($folderPath, $baseDir) {
    // Search through all playlist folder indexes to find the GUID for this folder
    $indexFiles = glob($baseDir . DIRECTORY_SEPARATOR . 'playlist-*-index.json');
    
    foreach ($indexFiles as $indexFile) {
        $indexData = file_get_contents($indexFile);
        $index = json_decode($indexData, true);
        
        if ($index && isset($index[$folderPath]) && isset($index[$folderPath]['guid'])) {
            return $index[$folderPath]['guid'];
        }
    }
    
    return null;
}

function getPlaylistFolders($playlist) {
    $folders = [];
    
    if (isset($playlist['scan-sub-folders']) && $playlist['scan-sub-folders']) {
        $subFolders = [];
        dirSubFoldersGet($playlist['path'], $subFolders, true);
        $folders = $subFolders;
    } else {
        // For non-sub-folder scanning, the playlist path itself is the only "folder"
        $folders = [$playlist['path']];
    }
    
    return $folders;
}

function createOrUpdatePlaylistFolderIndex($playlist, $playlistIndex, $baseDir) {
    // Generate sanitized playlist name
    $playlistName = isset($playlist['name']) ? $playlist['name'] : basename($playlist['path']);
    $sanitizedName = sanitizePlaylistName($playlistName);
    $indexFileName = "playlist-{$sanitizedName}-index.json";
    $indexFilePath = $baseDir . DIRECTORY_SEPARATOR . $indexFileName;
    
    // Get current folders for this playlist
    $currentFolders = getPlaylistFolders($playlist);
    
    // Load existing index if it exists
    $existingIndex = [];
    if (file_exists($indexFilePath)) {
        $indexData = file_get_contents($indexFilePath);
        $existingIndex = json_decode($indexData, true);
        if ($existingIndex === null) {
            $existingIndex = [];
        }
    }
    
    // Build updated index
    $updatedIndex = [];
    foreach ($currentFolders as $folder) {
        $updatedIndex[$folder] = [
            'play_count' => isset($existingIndex[$folder]) ? $existingIndex[$folder]['play_count'] : 0,
            'guid' => isset($existingIndex[$folder]['guid']) ? $existingIndex[$folder]['guid'] : generateGuid()
        ];
    }
    
    // Save the updated index
    file_put_contents($indexFilePath, json_encode($updatedIndex, JSON_PRETTY_PRINT));
    
    $logObj = [
        'log' => 'playlistFolderIndex',
        'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown',
        'playlist_name' => $playlistName,
        'index_file' => $indexFileName,
        'folder_count' => count($updatedIndex)
    ];
    error_log(json_encode($logObj));
    
    return [
        'file_path' => $indexFilePath,
        'file_name' => $indexFileName,
        'folder_count' => count($updatedIndex)
    ];
}

function incrementPlaylistFolderCount($playlist, $folderPath, $baseDir) {
    // Generate sanitized playlist name and index file path
    $playlistName = isset($playlist['name']) ? $playlist['name'] : basename($playlist['path']);
    $sanitizedName = sanitizePlaylistName($playlistName);
    $indexFileName = "playlist-{$sanitizedName}-index.json";
    $indexFilePath = $baseDir . DIRECTORY_SEPARATOR . $indexFileName;
    
    if (file_exists($indexFilePath)) {
        $indexData = file_get_contents($indexFilePath);
        $index = json_decode($indexData, true);
        
        if ($index && isset($index[$folderPath])) {
            $index[$folderPath]['play_count']++;
            file_put_contents($indexFilePath, json_encode($index, JSON_PRETTY_PRINT));
            
            $logObj = [
                'log' => 'folderPlayCountIncrement',
                'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown',
                'playlist_name' => $playlistName,
                'folder_path' => $folderPath,
                'new_count' => $index[$folderPath]['play_count']
            ];
            error_log(json_encode($logObj));
        }
    }
}

function generateGuid() {
    // Generate a UUID v4
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Find the PHP CLI binary path
 * PHP_BINARY may point to php-fpm in web server context, so we need to find the CLI version
 * 
 * @return string|null Path to PHP CLI binary, or null if not found
 */
function findPhpCliBinary() {
    // First, check if PHP_BINARY is already the CLI version
    $phpBinary = PHP_BINARY;
    
    // Check if current binary is php-fpm by looking at the name
    $binaryName = basename($phpBinary);
    $isFpm = (strpos($binaryName, 'fpm') !== false);
    
    if (!$isFpm) {
        // Verify it's actually CLI by checking if it can run simple command
        $testOutput = shell_exec(sprintf('"%s" -r "echo PHP_SAPI;" 2>/dev/null', $phpBinary));
        if (trim($testOutput) === 'cli') {
            return $phpBinary;
        }
    }
    
    // Try common PHP CLI locations
    $possiblePaths = [];
    
    // Get the directory of current PHP binary and look for cli version there
    $phpDir = dirname($phpBinary);
    $possiblePaths[] = $phpDir . '/php';
    $possiblePaths[] = $phpDir . '/php-cli';
    $possiblePaths[] = $phpDir . '/../bin/php';
    
    // Synology-specific paths
    $possiblePaths[] = '/usr/local/bin/php';
    $possiblePaths[] = '/usr/local/bin/php74';
    $possiblePaths[] = '/usr/local/bin/php80';
    $possiblePaths[] = '/usr/local/bin/php81';
    $possiblePaths[] = '/usr/local/bin/php82';
    $possiblePaths[] = '/usr/local/bin/php83';
    $possiblePaths[] = '/volume1/@appstore/PHP7.4/usr/local/bin/php74';
    $possiblePaths[] = '/volume1/@appstore/PHP8.0/usr/local/bin/php80';
    $possiblePaths[] = '/volume1/@appstore/PHP8.1/usr/local/bin/php81';
    $possiblePaths[] = '/volume1/@appstore/PHP8.2/usr/local/bin/php82';
    
    // Standard Unix paths
    $possiblePaths[] = '/usr/bin/php';
    $possiblePaths[] = '/bin/php';
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_executable($path)) {
            // Verify it's CLI
            $testOutput = shell_exec(sprintf('"%s" -r "echo PHP_SAPI;" 2>/dev/null', $path));
            if (trim($testOutput) === 'cli') {
                error_log(json_encode([
                    'log' => 'findPhpCliBinary',
                    'status' => 'found',
                    'path' => $path,
                    'original_binary' => $phpBinary
                ]));
                return $path;
            }
        }
    }
    
    // Try using 'which php' as fallback
    $whichPhp = trim(shell_exec('which php 2>/dev/null'));
    if (!empty($whichPhp) && file_exists($whichPhp) && is_executable($whichPhp)) {
        $testOutput = shell_exec(sprintf('"%s" -r "echo PHP_SAPI;" 2>/dev/null', $whichPhp));
        if (trim($testOutput) === 'cli') {
            error_log(json_encode([
                'log' => 'findPhpCliBinary',
                'status' => 'found_via_which',
                'path' => $whichPhp,
                'original_binary' => $phpBinary
            ]));
            return $whichPhp;
        }
    }
    
    error_log(json_encode([
        'log' => 'findPhpCliBinary',
        'status' => 'not_found',
        'original_binary' => $phpBinary,
        'searched_paths' => $possiblePaths
    ]));
    
    return null;
}

/**
 * Trigger asynchronous geolocation processing for an index file
 * This launches the geolocation processor in the background to avoid blocking the slideshow
 * 
 * @param string $indexFilePath Path to the index file that needs geolocation processing
 * @param int $batchSize Number of photos to process per batch (default: 5)
 * @return bool True if the process was triggered successfully
 */
function triggerAsyncGeolocationProcessing($indexFilePath, $batchSize = 5) {
    try {
        // Check if we should trigger processing (use a flag file to prevent multiple concurrent runs)
        $tempDir = dirname($indexFilePath);
        $lockFile = $tempDir . DIRECTORY_SEPARATOR . 'geolocation_processing.lock';
        
        error_log(json_encode([
            'log' => 'triggerAsyncGeolocation',
            'status' => 'starting',
            'index_file' => $indexFilePath,
            'temp_dir' => $tempDir,
            'lock_file' => $lockFile
        ]));
        
        // Check if processing is already running (lock file exists and is recent)
        if (file_exists($lockFile)) {
            $lockAge = time() - filemtime($lockFile);
            // If lock is less than 5 minutes old, skip triggering
            if ($lockAge < 300) {
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'skipped',
                    'reason' => 'lock_file_active',
                    'lock_age_seconds' => $lockAge,
                    'lock_file' => $lockFile
                ]));
                return false;
            }
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'lock_expired',
                'lock_age_seconds' => $lockAge
            ]));
        }
        
        // Create/update lock file
        $lockWriteResult = file_put_contents($lockFile, json_encode([
            'started' => time(),
            'triggered_by' => basename($indexFilePath)
        ]));
        
        if ($lockWriteResult === false) {
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'error',
                'message' => 'Failed to write lock file',
                'lock_file' => $lockFile
            ]));
            return false;
        }
        
        // Determine the path to the geolocation processor script
        $processorScript = __DIR__ . DIRECTORY_SEPARATOR . 'process_geolocation.php';
        
        if (!file_exists($processorScript)) {
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'error',
                'message' => 'Geolocation processor script not found',
                'script' => $processorScript
            ]));
            return false;
        }
        
        // Find PHP CLI executable
        // PHP_BINARY may point to php-fpm in web server context, so we need to find the CLI version
        $phpBinary = findPhpCliBinary();
        
        error_log(json_encode([
            'log' => 'triggerAsyncGeolocation',
            'status' => 'preparing_launch',
            'php_binary' => $phpBinary,
            'php_binary_original' => PHP_BINARY,
            'processor_script' => $processorScript,
            'php_os' => PHP_OS
        ]));
        
        if ($phpBinary === null) {
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'error',
                'message' => 'Could not find PHP CLI binary',
                'php_binary_original' => PHP_BINARY
            ]));
            return false;
        }
        
        // Launch the processor in the background
        // On Windows, use 'start /B', on Unix use '&'
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Use popen to launch without waiting
            $command = sprintf('start /B "" "%s" "%s" --batch-size=%d', $phpBinary, $processorScript, $batchSize);
            
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'executing_windows',
                'command' => $command
            ]));
            
            $handle = popen($command, 'r');
            if ($handle === false) {
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'error',
                    'message' => 'popen failed to execute command',
                    'command' => $command
                ]));
                return false;
            }
            $closeResult = pclose($handle);
            
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'windows_pclose_result',
                'pclose_return' => $closeResult
            ]));
        } else {
            // Unix/Linux: Use nohup to run in background
            // Log to a file for debugging instead of /dev/null
            $logFile = $tempDir . DIRECTORY_SEPARATOR . 'geolocation_process.log';
            
            // Check if PHP binary is executable
            if (!is_executable($phpBinary)) {
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'error',
                    'message' => 'PHP binary is not executable',
                    'php_binary' => $phpBinary,
                    'file_exists' => file_exists($phpBinary),
                    'is_readable' => is_readable($phpBinary)
                ]));
                return false;
            }
            
            // Check if processor script is readable
            if (!is_readable($processorScript)) {
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'error',
                    'message' => 'Processor script is not readable',
                    'script' => $processorScript
                ]));
                return false;
            }
            
            // Build command with output logging for debugging
            $command = sprintf(
                'nohup "%s" "%s" --batch-size=%d >> "%s" 2>&1 & echo $!',
                $phpBinary,
                $processorScript,
                $batchSize,
                $logFile
            );
            
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'executing_unix',
                'command' => $command,
                'log_file' => $logFile
            ]));
            
            // Use shell_exec to capture the PID
            $pid = trim(shell_exec($command));
            
            error_log(json_encode([
                'log' => 'triggerAsyncGeolocation',
                'status' => 'unix_launched',
                'pid' => $pid,
                'pid_is_numeric' => is_numeric($pid)
            ]));
            
            // Verify the process is actually running
            if (is_numeric($pid) && $pid > 0) {
                // Give process a moment to start
                usleep(100000); // 100ms
                
                // Check if process is still running
                $checkCommand = sprintf('ps -p %d -o pid= 2>/dev/null', (int)$pid);
                $psOutput = trim(shell_exec($checkCommand));
                $isRunning = !empty($psOutput);
                
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'process_check',
                    'pid' => $pid,
                    'is_running' => $isRunning,
                    'ps_output' => $psOutput
                ]));
                
                if (!$isRunning) {
                    // Process died immediately - check the log file for errors
                    if (file_exists($logFile)) {
                        $logContents = file_get_contents($logFile);
                        // Get last 1000 chars to avoid huge logs
                        $logTail = substr($logContents, -1000);
                        error_log(json_encode([
                            'log' => 'triggerAsyncGeolocation',
                            'status' => 'process_died',
                            'pid' => $pid,
                            'log_tail' => $logTail
                        ]));
                    }
                }
            } else {
                error_log(json_encode([
                    'log' => 'triggerAsyncGeolocation',
                    'status' => 'error',
                    'message' => 'Failed to get PID from shell_exec',
                    'pid_value' => $pid
                ]));
                return false;
            }
        }
        
        error_log(json_encode([
            'log' => 'triggerAsyncGeolocation',
            'status' => 'triggered',
            'index_file' => basename($indexFilePath)
        ]));
        
        return true;
        
    } catch (Exception $e) {
        error_log(json_encode([
            'log' => 'triggerAsyncGeolocation',
            'status' => 'exception',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]));
        return false;
    } catch (Error $e) {
        error_log(json_encode([
            'log' => 'triggerAsyncGeolocation',
            'status' => 'fatal_error',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]));
        return false;
    }
}

function sanitizePlaylistName($name) {
    // Remove or replace characters that are not safe for filenames (preserve hyphens and underscores)
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    // Remove multiple consecutive underscores
    $sanitized = preg_replace('/_+/', '_', $sanitized);
    // Remove leading/trailing underscores
    $sanitized = trim($sanitized, '_');
    return $sanitized;
}

function playlistScanBuild ($Playlist) {
    $playlistSizeMap = array();

    foreach($Playlist as $key => $plitem){
        $folderCount = 1;

        if ($plitem['scan-sub-folders']) {
            $folders = null;
            dirSubFoldersGet($plitem['path'], $folders, true);
            $folderCount = count($folders);
        }

        $playlistSizeMap = array_merge($playlistSizeMap, array_fill(0, $folderCount, $key)); 
    };

    return $playlistSizeMap;
}

// Function getDirContents was written by stackoverflow user user2226755
// URL: https://stackoverflow.com/questions/24783862/list-all-the-files-and-folders-in-a-directory-with-php-recursive-function
function dirContentsGet($dir, $filter = '', &$results = array(), $excludeText = '') {
    $files = scandir($dir);
    foreach($files as $key => $value){
        $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
        if(!is_dir($path)) {
            // Apply filter (inclusion pattern)
            if(empty($filter) || preg_match($filter, $path)) {
                // Apply exclusion text filter (case-insensitive)
                if (empty($excludeText) || stripos($path, $excludeText) === false) {
                    $results[] = $path;
                }
            }
        } elseif($value != "." && $value != "..") {
            dirContentsGet($path, $filter, $results, $excludeText);
        }
    }
    // Store the current date and time in the session
    $_SESSION['LastFileScan'] = time();
    //$_SESSION['LastFileScan'] = date('Y-m-d H:i:s');
    return $results;
}

//Folders one level deep, when $recurse=false.
//In recursion the resulting list is flat, so simple count can be used
function dirSubFoldersGet($dir, 
    &$results = array(),
    $recurse = false)
{
    try {

        $files = scandir($dir);

        foreach($files as $key => $value) {
            if (($value !=='.')
                && ($value !== '..')) 
            {
                $path = realpath($dir . DIRECTORY_SEPARATOR . $value);

                if ((!str_ends_with($path, '@eaDir'))
                    && (!str_ends_with($path, DIRECTORY_SEPARATOR . 'temp'))
                    && ($value !== 'temp')
                    && (is_dir($path))){
                
                        $results[] = $path;
                        if ($recurse)
                        {
                            dirSubFoldersGet($path, $results, true);
                        }
                }
            }
        }
    }
    catch (Exception $e)
    {
        error_log('dirSubFoldersGet problem for = ' . $dir);
    }

    return $results;
}

function stringSplitLast($Input, $Split) {
    $ret = '';

    $parts = explode($Split, $Input);

    if (($parts) && (count($parts)>0))
    {
        $ret = end($parts);
    }

    return $ret;
}

?>