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
            font-family: Helvetica, Sans-Serif;
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

        .photo-info-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            bottom: 0px;
            margin-bottom: 0px;
            width: 100%;
        }

        .photo-info-container h2 {
            flex: 1;
            position: static;
            margin: 0;
        }

        .info-button {
            margin-left: 10px;
            margin-right: 10px;
            padding: 8px 16px;
            cursor: pointer;
            background: rgba(255,255,255,0.2);
            border: 1px solid color-mix(in srgb, <?=$textColor?> 50%, transparent);
            color: color-mix(in srgb, <?=$textColor?> 50%, transparent);
            border-radius: 4px;
            font-size: 14px;
        }

        .info-button:hover {
            background: rgba(255,255,255,0.3);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: #2c2c2c;
            padding: 30px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            color: white;
            position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }

        .modal-content h3 {
            margin-top: 0;
            border-bottom: 2px solid #555;
            padding-bottom: 10px;
            color: white;
            background: transparent;
        }

        .modal-content div {
            background: transparent;
        }

        .modal-content div strong {
            background: transparent;
        }

        .info-content {
            line-height: 2;
        }

        .close-button {
            margin-top: 20px;
            padding: 10px 20px;
            cursor: pointer;
            background: white;
            border: none;
            color: #2c2c2c;
            border-radius: 4px;
            font-size: 14px;
            width: 100%;
        }

        .close-button:hover {
            background-color: grey;
        }

    </style>
</head>
<body>

    <div id="photoInfoModal" class="modal">
        <div class="modal-content">
            <h3>Photo Information</h3>
            <div id="photoInfoContent" class="info-content"></div>
            <button onclick="closePhotoInfo()" class="close-button">Close</button>
        </div>
    </div>

    <script>
        var currentPhotoInfo = {};

        function showPhotoInfo() {
            var modal = document.getElementById("photoInfoModal");
            var content = "";
            
            if (currentPhotoInfo.year && currentPhotoInfo.year !== "-") {
                content += "<div><strong>Year:</strong> " + currentPhotoInfo.year + "</div>";
            }
            if (currentPhotoInfo.month && currentPhotoInfo.month !== "-") {
                content += "<div><strong>Month:</strong> " + currentPhotoInfo.month + "</div>";
            }
            if (currentPhotoInfo.display_name) {
                content += "<div><strong>Album:</strong> " + currentPhotoInfo.display_name + "</div>";
            }
            if (currentPhotoInfo.country) {
                content += "<div><strong>Country:</strong> " + currentPhotoInfo.country + "</div>";
            }
            if (currentPhotoInfo.village) {
                content += "<div><strong>Village:</strong> " + currentPhotoInfo.village + "</div>";
            }
            if (currentPhotoInfo.town) {
                content += "<div><strong>Town:</strong> " + currentPhotoInfo.town + "</div>";
            }
            if (currentPhotoInfo.city) {
                content += "<div><strong>City:</strong> " + currentPhotoInfo.city + "</div>";
            }
            if (currentPhotoInfo.file_path) {
                content += "<div><strong>File:</strong> " + currentPhotoInfo.file_path + "</div>";
            }
            
            document.getElementById("photoInfoContent").innerHTML = content;
            modal.style.display = "flex";
        }
        
        function closePhotoInfo() {
            document.getElementById("photoInfoModal").style.display = "none";
        }

        // Close modal when clicking outside of it
        document.getElementById("photoInfoModal").addEventListener("click", function(e) {
            if (e.target === this) {
                closePhotoInfo();
            }
        });
    </script>

    <?php if (!empty($_SESSION['current-photo-info'])) : ?>
    <script>
        currentPhotoInfo = <?php echo json_encode($_SESSION['current-photo-info'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <?php endif; ?>

    <?php if (empty($errorForUser)) : ?>
    <img src="/image.php" />
    <?php else : ?>
    <h2><?php echo $errorForUser; ?></h2>
    <?php endif; ?>

</body>
</html>
