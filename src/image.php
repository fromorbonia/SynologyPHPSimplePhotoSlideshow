<?php

$request = $_SERVER['REQUEST_URI'];

error_log('incoming = '.$request.'*');

$querystring=explode('?', $request);

if (count($querystring) > 1)
{
    $pathparts=explode('=', $querystring[1]);
    $pathparts=urldecode($pathparts[1]);

    $path= '/volume1/photo/' . $pathparts;
    error_log('image = '.$path.'*');

    if (file_exists($path)) {

       $image_info = getimagesize($path);

       //Set the content-type header as appropriate
       header('Content-Type: ' . $image_info['mime']);

       //Set the content-length header
       header('Content-Length: ' . filesize($path));

       //Write the image bytes to the client
       readfile($path);
       exit;
    }
    else { // Image file not found

        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");

    }
}

?>