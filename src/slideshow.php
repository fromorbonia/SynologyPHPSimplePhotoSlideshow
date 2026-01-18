<?php

require_once 'slidelogic.php';
require_once 'slidefunctions.php';

// Session variables are used to prevent rescaning photo folders every time the page is refreshed.
session_start();

// Configure temp directory for index files
$tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Configure playlists index file path in temp directory
$playlistsIndexFile = $tempDir . DIRECTORY_SEPARATOR . 'playlists_index.json';

// Make it available globally for other functions
$GLOBALS['playlistsIndexFile'] = $playlistsIndexFile;

// Load config with caching functionality
$config = loadConfigWithCaching('slideconfig.json', $playlistsIndexFile);

// Background and text colors
$backgroundColor = $config['display']['backgroundColor'];
$textColor = $config['display']['textColor'];

// ChatGPT came up with some background colours for a cream iPad: Light Grey (#E0E0E0); Off-White (#F5F5F5); Silver (#C0C0C0)


// Interval is the number of seconds between each photo
$interval = $config['display']['interval'];

// photoExt is the file extension of the photos. Do not include '.'. Not case-sensitive.
$photoExt = 'jpg';
$errorForUser = '';

// Number of minutes after which the photo directory will be rescanned. The rescan is triggered by recreating the session to force a file rescan
$rescanAfter = $config['display']['rescanAfter'];

// Don't display photos with the following text in the filename. Not case sensitive.
$excludeText = 'SYNOPHOTO_THUMB';

//Build simple array reflecting number of folders in each playlist - there will be a more elegant way
if(empty($_SESSION['playlist-size-map'])){
    $_SESSION['playlist-size-map'] = playlistScanBuild($config['playlist']);
    $_SESSION['playlist-scanid'] = date('d-H:i:s');
    $logObj = [ 'log' => 'map',
        'scanID' => $_SESSION['playlist-scanid'], 
        'playlist-len' => count($config['playlist']),
        'playlist-sm-len' => count($_SESSION['playlist-size-map'])];
    error_log(json_encode($logObj));
}

//Setup and run photo selection using $SESSION variables:
PrepAndSelect($_SESSION['playlist-size-map'], 
    $config['playlist'],
    $config['playlist-root'], 
    $photoExt, 
    $rescanAfter, 
    $excludeText);


?>
<!DOCTYPE html>
<html>
<head>
    <title>Simple Random Photo Slideshow</title>
    <meta http-equiv="refresh" content="<?=$interval?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <style>
        * {
            background-color: <?=$backgroundColor?>;
        }

        img {
            object-fit: contain;
            width: 95vw;
            height: 95vh;
            margin: auto;
            display: block;
        }

        pre {
            color: <?=$textColor?>;
            text-align: center;
        }

        h2 {
            color: <?=$textColor?>;
            background: <?=$backgroundColor?>;
            font: bold 1.5em Helvetica, Sans-Serif;
            position: fixed;
            bottom: 0px;
            margin-bottom: 0px;
            text-align: center;
            width: 100%;
        }

        .ellipsis {
            overflow: hidden;
            white-space: nowrap;
            width: 100%;
            text-overflow: ellipsis;
        }

    </style>
</head>
<body>

    <?php if (empty($errorForUser)) : ?>
    <img src="/image.php" />
    <?php else : ?>
    <h2><?php echo $errorForUser; ?></h2>
    <?php endif; ?>

</body>
</html>
