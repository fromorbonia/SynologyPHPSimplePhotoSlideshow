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
                $geoFields = ['gps_lat', 'gps_lon', 'country', 'city', 'geocode_status', 'geocode_timestamp'];
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
        triggerAsyncGeolocationProcessing($indexFilePath);
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
 * Trigger asynchronous geolocation processing for an index file
 * This launches the geolocation processor in the background to avoid blocking the slideshow
 * 
 * @param string $indexFilePath Path to the index file that needs geolocation processing
 * @return bool True if the process was triggered successfully
 */
function triggerAsyncGeolocationProcessing($indexFilePath) {
    // Check if we should trigger processing (use a flag file to prevent multiple concurrent runs)
    $tempDir = dirname($indexFilePath);
    $lockFile = $tempDir . DIRECTORY_SEPARATOR . 'geolocation_processing.lock';
    
    // Check if processing is already running (lock file exists and is recent)
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        // If lock is less than 5 minutes old, skip triggering
        if ($lockAge < 300) {
            return false;
        }
    }
    
    // Create/update lock file
    file_put_contents($lockFile, json_encode([
        'started' => time(),
        'triggered_by' => basename($indexFilePath)
    ]));
    
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
    
    // Find PHP executable
    $phpBinary = PHP_BINARY;
    
    // Launch the processor in the background
    // On Windows, use 'start /B', on Unix use '&'
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: Use popen to launch without waiting
        $command = sprintf('start /B "" "%s" "%s" --batch-size=5', $phpBinary, $processorScript);
        pclose(popen($command, 'r'));
    } else {
        // Unix/Linux: Use nohup to run in background
        $command = sprintf('nohup "%s" "%s" --batch-size=5 > /dev/null 2>&1 &', $phpBinary, $processorScript);
        exec($command);
    }
    
    error_log(json_encode([
        'log' => 'triggerAsyncGeolocation',
        'status' => 'triggered',
        'index_file' => basename($indexFilePath)
    ]));
    
    return true;
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