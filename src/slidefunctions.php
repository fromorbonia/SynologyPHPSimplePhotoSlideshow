<?php

//**************************************************************
//    Common functions
//**************************************************************


function configGet ($configFile) {
    $config_data = file_get_contents($configFile);
    $config = json_decode($config_data, true);
    
    if ($config === null) {
        return null;
    }
    
    // Get the directory path of the config file
    $configDir = dirname($configFile);
    $indexFile = $configDir . DIRECTORY_SEPARATOR . 'playlist_index.json';
    
    // Load or create the playlist index
    $index = [];
    if (file_exists($indexFile)) {
        $indexData = file_get_contents($indexFile);
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
            'path' => $playlistInfo['path'],
            'play_count' => isset($index[$playlistId]) ? $index[$playlistId]['play_count'] : 0
        ];
    }
    
    // Save the updated index back to file
    file_put_contents($indexFile, json_encode($updatedIndex, JSON_PRETTY_PRINT));
    
    // Add the index to the config for use by other parts of the application
    $config['playlist_index'] = $updatedIndex;
    
    return $config;
}

function playlistIncrementPlayCount($playlistPath, $configFile = 'slideconfig.json') {
    $configDir = dirname($configFile);
    $indexFile = $configDir . DIRECTORY_SEPARATOR . 'playlist_index.json';
    
    if (file_exists($indexFile)) {
        $indexData = file_get_contents($indexFile);
        $index = json_decode($indexData, true);
        
        if ($index && isset($index[$playlistPath])) {
            $index[$playlistPath]['play_count']++;
            file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
            
            $logObj = [ 'log' => 'playCountIncrement',
                'scanID' => $_SESSION['playlist-scanid'] ?? 'unknown', 
                'playlist' => $playlistPath,
                'new_count' => $index[$playlistPath]['play_count']];
            error_log(json_encode($logObj));
        }
    }
}

function playlistPick ($PlaylistMap, $Playlist) {
    $total = count($PlaylistMap);
    $rd = random_int(0, $total-1);
    $val = array_values($PlaylistMap)[$rd];
    
    $selectedPlaylist = $Playlist[$val];
    
    // Increment play count for the selected playlist
    if (isset($selectedPlaylist['path'])) {
        playlistIncrementPlayCount($selectedPlaylist['path']);
    }
    
    $logObj = [ 'log' => 'playlistPick',
        'scanID' => $_SESSION['playlist-scanid'], 
        'rand' => $rd,
        'idx to use' => $val,
        'playlist_path' => $selectedPlaylist['path'] ?? 'unknown'];
    error_log(json_encode($logObj));
    return $selectedPlaylist;
}

function playlistItemPhotos($plitem, $photoExt, &$photoFolder)
{

    if ($plitem['scan-sub-folders'] == false) {
        $photoFolder = $plitem['path'];
        return dirContentsGet($plitem['path'], '/\.' . $photoExt . '$/i');
    } else {
        $dirs = dirSubFoldersGet($plitem['path']);
        $dirKey = array_keys($dirs)[random_int(0, count($dirs)-1)];
        $retPhotos = dirContentsGet($dirs[$dirKey], '/\.' . $photoExt . '$/i');
        $photoFolder = $dirs[$dirKey];
        return $retPhotos;
    }
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
function dirContentsGet($dir, $filter = '', &$results = array()) {
    $files = scandir($dir);
    foreach($files as $key => $value){
        $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
        if(!is_dir($path)) {
            if(empty($filter) || preg_match($filter, $path)) $results[] = $path;
        } elseif($value != "." && $value != "..") {
            dirContentsGet($path, $filter, $results);
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