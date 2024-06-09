<?php

//**************************************************************
//    Primary logic for selecting and photo to display
//**************************************************************

function PrepAndSelect($Playlist,
    $PlaylistRoot,
    $PhotoExt,
    $RescanAfter,
    $ExcludeText) {

    if(empty($_SESSION['playlist-item'])){
        //No current folder active, so choose one at random:
        $_SESSION['playlist-item'] = playlistPick($Playlist);
        $_SESSION['photos'] = null;
        playlistScanBuild($Playlist);
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
        // Check the age of the array containing file names and destroy the session if the age exceeds $rescanAfter
        // This forces a rescan of $photoDir on the next page refresh if the age of the file list exceeds $rescanAfter
        $arrayAge = time() - $_SESSION['LastFileScan'];
        if ($arrayAge > ($RescanAfter * 60)) {
            session_destroy();
        }
    
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
    
    
                    //Set the current photo for image.php to display
                    $_SESSION['photo-current'] = $photo;
    
                    // Display the filename of the photo and DateTimeOriginal
                    echo ("<h2 class=\"ellipsis\">Year: " . $photoYear . "&nbsp;&nbsp;&nbsp; Month: " . $photoMonth . "&nbsp;&nbsp;&nbsp;" .
                        substr($_SESSION['photos-folder'], strlen($PlaylistRoot) + 1) . "</h2>");
                    
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
        }
    
    }
    
    if (empty($_SESSION['photos'])) {
        //Time to fetch a new set of photos, by clearing
        unset($_SESSION['playlist-item']);
    }
    
    
}

?>