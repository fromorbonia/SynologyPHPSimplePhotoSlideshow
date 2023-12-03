<?php

// Use session variable for current photo to avoid using url to transfer 
// Previous version passed path to this page, but not great from a security perspective 
session_start();


if (!empty($_SESSION['photo-current'])) {

    $path = $_SESSION['photo-current'];

    error_log('image = ' . $path . '*');

    if (file_exists($path)) {

        $image_info = getimagesize($path);

        //Set the content-type header as appropriate
        header('Content-Type: ' . $image_info['mime']);

        //Set the content-length header
        header('Content-Length: ' . filesize($path));

        //Write the image bytes to the client
        readfile($path);
        exit;
    } else { // Image file not found

        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");

    }
}
else
{
    error_log('Session photo-current empty');
}

?>
