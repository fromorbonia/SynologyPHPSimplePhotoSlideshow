<?php


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

function dirSubFoldersGet($dir, &$results = array())
{
    $files = scandir($dir);

    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if ((is_dir($path))
            && ($value !='.')
            && ($value != '..')){
                $results[] = $path;
        }
    }
    return $results;
}
?>