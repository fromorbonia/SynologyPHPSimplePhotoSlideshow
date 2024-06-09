<?php

//**************************************************************
//    Common functions
//**************************************************************


function configGet ($configFile) {
    $config_data = file_get_contents($configFile);
    return json_decode($config_data, true);
}

function playlistPick ($playlist) {
    $key = array_rand($playlist, 1);
    return $playlist[$key];
}

function playlistItemPhotos($plitem, $photoExt, &$photoFolder)
{

    if ($plitem['scan-sub-folders'] == false) {
        $photoFolder = $plitem['path'];
        return dirContentsGet($plitem['path'], '/\.' . $photoExt . '$/i');
    } else {
        $dirs = dirSubFoldersGet($plitem['path']);
        $dirKey = array_rand($dirs, 1);
        $retPhotos = dirContentsGet($dirs[$dirKey], '/\.' . $photoExt . '$/i');
        $photoFolder = $dirs[$dirKey];
        echo $photoFolder;
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
            $folderCount += count($folders);
        }
        echo "dir=".$plitem['path']." has foldercount=".$folderCount."\r\n";
        //print_r($folders);
                        //var_dump($folders);
        echo "key=".$key;
        array_push($playlistSizeMap, array_fill(0, $folderCount, $key)); 
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
        //echo "Scanning dir=".$dir."\n";

        $files = scandir($dir);

        foreach($files as $key => $value) {
            if (($value !=='.')
                && ($value !== '..')) 
            {
                $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
                //echo "subpath=".$path."\n";
                if ((!str_ends_with($path, '@eaDir'))
                    && (is_dir($path))){
                        //echo "isdir=".$path."\r\n";
                        //echo "\n";
                
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



?>