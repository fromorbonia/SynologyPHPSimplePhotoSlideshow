<?php

//**************************************************************
//    Primary logic for selecting and photo to display
//**************************************************************

function selectAndDisplayPhoto($ExcludeText) {
    //Delete the currrent selected photo, which has already been removed from the list of photos
    unset($_SESSION['photo-current']);

    // Select a random photo if the filename does not contain $excludeText
    $photosCount = count($_SESSION['photos']);

    while ($photosCount > 0){
        $random = array_rand($_SESSION['photos'], 1);

        $photo = $_SESSION['photos'][$random];

        if (stripos($photo, $ExcludeText) <= 0) {

            if (file_exists($photo)) {

                // Get the DateTimeOriginal field from the photo EXIF data
                $photoExif = exif_read_data($_SESSION['photos'][$random], 'IFD0');
                $photoYear = '-';
                $photoMonth = '-';
                if (
                    (!empty($photoExif))
                    && (array_key_exists('DateTimeOriginal', $photoExif))
                ) {
                    $photoYear = date("Y", strtotime($photoExif['DateTimeOriginal']));
                    $photoMonth = date("M", strtotime($photoExif['DateTimeOriginal']));
                }

                $plName = stringSplitLast($_SESSION['playlist-item']['path'], '/');
                $fldName = stringSplitLast($_SESSION['photos-folder'], '/');
                if (($fldName) && ($plName != $fldName)) {
                    $plName = $plName . ' - ' . $fldName;
                }


                //Set the current photo for image.php to display
                $_SESSION['photo-current'] = $photo;

                // Display the filename of the photo and DateTimeOriginal
                echo ("<h2 class=\"ellipsis\">Year: " . $photoYear . "&nbsp;&nbsp;&nbsp; Month: " . $photoMonth . "&nbsp;&nbsp;&nbsp;" .
                    $plName . "</h2>");
                
                $_SESSION['photos-displayed-count'] += 1;

                if ((isset($_SESSION['playlist-item']['max-photos-per-select']))
                    && ($_SESSION['photos-displayed-count']>= $_SESSION['playlist-item']['max-photos-per-select']))
                {
                    //Exceeded the maximum number of photos for this cycle, so clear list 
                    unset($_SESSION['photos']);
                }
                    
                break;
            } else {
                error_log('Photo found in initial scan, but file does not exist = ' . $photo);
            }
        }

        if (!empty($_SESSION['photos']))
        {
            //No matter what, remove the photo from the array, ready to get the next one
            unset($_SESSION['photos'][$random]);
        }

        $photosCount -= 1;
    }
}

function PrepAndSelect($PlaylistMap,
    $Playlist,
    $PlaylistRoot,
    $PhotoExt,
    $RescanAfter,
    $ExcludeText) {

    try {
        if(empty($_SESSION['playlist-item'])){
            //No current folder active, so choose one at random:
            //var_dump($PlaylistMap);
            global $playlistsIndexFile;
            $_SESSION['playlist-item'] = playlistPick($PlaylistMap, $Playlist, $playlistsIndexFile);
            //var_dump($_SESSION['playlist-item']);
            $_SESSION['photos'] = null;
        }
        
        // If the list of photos is empty, then get a list of photos
        // This should also detect when the array is empty
        if(empty($_SESSION['photos'])) {
            $_SESSION['photos-folder'] = '-';
            $_SESSION['photos-displayed-count'] = 0;
            $_SESSION['photos'] = playlistItemPhotos($_SESSION['playlist-item'], $PhotoExt, $_SESSION['photos-folder']);
        
            if (empty($_SESSION['photos'])
                || (count($_SESSION['photos']) <=0))
            {
                $errorForUser = 'Could not load any photos for playlist: ' . $_SESSION['playlist-item']['path'];
            }
        }
        
        if (empty($errorForUser)) {

            $sessionRestart = false;

            if ($RescanAfter>0)
            {
                // Check the age of the array containing file names and destroy the session if the age exceeds $rescanAfter
                // This forces a rescan of $photoDir on the next page refresh if the age of the file list exceeds $rescanAfter
                $arrayAge = time() - $_SESSION['LastFileScan'];
                if ($arrayAge > ($RescanAfter * 60)) {
                    error_log('Last file scan longer than '. $RescanAfter . ' - destroy session');
                    //TODO: destory loses the view history from the last hour...
                    //      Maybe just deteect change in number of folders and destroy at that point
                    session_destroy();
                    $sessionRestart = true;
                }
            } else {
                
                if (empty($_SESSION['config-modified'])) {
                    $_SESSION['config-modified'] = filemtime('slideconfig.json');
                }
                else if ($_SESSION['config-modified'] != filemtime('slideconfig.json'))
                {
                    error_log('Config modified - destroy session');
                    session_destroy();
                    $sessionRestart = true;
                }
            }

            if ($sessionRestart != true) {
                selectAndDisplayPhoto($ExcludeText);
            }
        
        }
        
        if (empty($_SESSION['photos'])) {
            //Time to fetch a new set of photos, by clearing
            unset($_SESSION['playlist-item']);
        }
    }
    catch (Exception $e)
    {
        error_log('PrepAndSelect problem with error = ' . $e->getMessage());
    }
    
}

?>