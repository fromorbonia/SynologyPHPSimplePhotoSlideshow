<?php

require_once 'slidefunctions.php';

// Session variables are used to prevent rescaning photo folders every time the page is refreshed.
session_start();


$config = configGet('slideconfig.json');

// Interval is the number of seconds between each photo
$interval = $config['display']['interval'];

// photoExt is the file extension of the photos. Do not include '.'. Not case-sensitive.
$photoExt = 'jpg';

$errorForUser = '';

// Background and text colors
$backgroundColor = $config['display']['backgroundColor'];
$textColor = $config['display']['textColor'];

// Number of minutes after which the photo directory will be rescanned. The rescan is triggered by recreating the session to force a file rescan
$recanAfter = 30;

// Don't display photos with the following text in the filename. Not case sensitive.
$excludeText = 'SYNOPHOTO_THUMB';

?>
<!DOCTYPE html>
<html>
<head>
 <title>Simple Random Photo Slideshow</title>
 <meta http-equiv="refresh" content="<?=$interval?>" >
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <meta name="apple-mobile-web-app-capable" content="yes">
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
  position: absolute;
  bottom: 0px;
  text-align: center;
  width: 100%;
 }

 .ellipsis {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }
 </style>
</head>
<body>

    <?php

    if(empty($_SESSION['playlist-item'])){
	    //No current folder active, so choose one at random:
        $_SESSION['playlist-item'] = playlistPick($config['playlist']);
        $_SESSION['photos'] = null;
    }

    // If the list of photos is empty get a list of photos
    // This should also detect when the array is empty
    if(empty($_SESSION['photos'])) {
        $_SESSION['photos-folder'] = '-';
        $_SESSION['photos'] = playlistItemPhotos($_SESSION['playlist-item'], $photoExt, $_SESSION['photos-folder']);

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
        if ($arrayAge > ($recanAfter * 60)) {
            session_destroy();
        }

        // Select a random photo if the filename does not contain $excludeText
        do {
            $random = array_rand($_SESSION['photos'], 1);
            $photo = str_replace($_SERVER['DOCUMENT_ROOT'], "", $_SESSION['photos'][$random]);
            $photo = '/image.php?path=' . urlencode(substr($photo, strlen($config['playlist-root'])));
        } while (stristr($photo, $excludeText));


        // Get the DateTimeOriginal field from the photo EXIF data
        $photoExif = exif_read_data($_SESSION['photos'][$random], 'IFD0');
        $photoYear = '-';
        $photoMonth = '-';
        if (array_key_exists('DateTimeOriginal', $photoExif)) {
            $photoYear = date("Y", strtotime($photoExif['DateTimeOriginal']));
            $photoMonth = date("M", strtotime($photoExif['DateTimeOriginal']));
        }
        //Remove the photo from the array:
        unset($_SESSION['photos'][$random]);

        // Display the filename of the photo and DateTimeOriginal
        echo ("<h2 class=\"ellipsis\">Year: " . $photoYear . "&nbsp;&nbsp;&nbsp; Month: " . $photoMonth . "&nbsp;&nbsp;&nbsp;" .
            substr($_SESSION['photos-folder'], strlen($config['playlist-root']) + 1) . "</h2>");
    }

    if (empty($_SESSION['photos'])) {
        //Time to fetch a new set of photos, by clearing
        $_SESSION['playlist-item'] = null;
    }


    ?>

<?php if (empty($errorForUser)) : ?>
    <img src="<?=$photo?>"/>
<?php else : ?>
    <h2> <?php echo $errorForUser; ?> </h2>
<?php endif; ?>

</body>
</html>