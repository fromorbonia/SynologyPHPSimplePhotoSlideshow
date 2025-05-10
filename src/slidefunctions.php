<?php

//**************************************************************
//    Common functions
//**************************************************************


function configGet ($configFile) {
    $config_data = file_get_contents($configFile);
    return json_decode($config_data, true);
}

function playlistPick ($PlaylistMap, $Playlist) {
    $total = count($PlaylistMap);
    $rd = random_int(0, $total-1);
    $val = array_values($PlaylistMap)[$rd];
    $logObj = [ 'log' => 'playlistPick',
        'scanID' => $_SESSION['playlist-scanid'], 
        'rand' => $rd,
        'idx to use' => $val];
    error_log(json_encode($logObj));
    return $Playlist[$val];
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