<?php

/**
 * Build the full stats payload from the index files in $tempDir.
 * Returns an array ready to be JSON-encoded for the client.
 */
function buildStatsPayload(string $tempDir): array {
    $stats = [
        'generated_at' => date('c'),
        'playlists'    => [],
        'errors'       => [],
    ];

    if (!is_dir($tempDir)) {
        $stats['errors'][] = 'Temp directory not found: ' . htmlspecialchars($tempDir);
        return $stats;
    }

    $playlistsIndexFile = $tempDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
    if (!file_exists($playlistsIndexFile)) {
        $stats['errors'][] = 'playlists_index.json not found - run the slideshow at least once.';
        return $stats;
    }

    $playlistsIndex = json_decode(file_get_contents($playlistsIndexFile), true);
    if (!is_array($playlistsIndex)) {
        $stats['errors'][] = 'Could not parse playlists_index.json.';
        return $stats;
    }

    $playlistFolderFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'playlist-*-index.json') ?: [];
    $folderFileByPlaylist = [];

    foreach ($playlistFolderFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }
        $folderFileByPlaylist[basename($file)] = $data;
    }

    // Each folderpics-{guid}-index.json maps photoPath => { play_count, ... }
    $guidToPhotoStats = [];
    $folderpicFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'folderpics-*-index.json') ?: [];

    foreach ($folderpicFiles as $file) {
        if (!preg_match('/folderpics-([^-]+-[^-]+-[^-]+-[^-]+-[^-]+)-index\.json$/', basename($file), $m)) {
            continue;
        }
        $guid = $m[1];
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }

        $photoCount = count($data);
        $totalViews = 0;
        foreach ($data as $photoInfo) {
            $totalViews += $photoInfo['play_count'] ?? 0;
        }

        $guidToPhotoStats[$guid] = [
            'photo_count' => $photoCount,
            'total_views' => $totalViews,
        ];
    }

    foreach ($playlistsIndex as $playlistPath => $playlistMeta) {
        $playlistName = $playlistMeta['name'] ?? basename($playlistPath);
        $playCount = $playlistMeta['play_count'] ?? 0;

        // Must mirror sanitizePlaylistName() in slidefunctions.php
        $sanitizedName = sanitizePlaylistNamePHP($playlistName);
        $folderIndexKey = "playlist-{$sanitizedName}-index.json";

        $folders = [];
        $totalPhotos = 0;
        $totalPhotoViews = 0;

        if (isset($folderFileByPlaylist[$folderIndexKey])) {
            foreach ($folderFileByPlaylist[$folderIndexKey] as $folderPath => $folderInfo) {
                $guid = $folderInfo['guid'] ?? null;
                $folderPlayCount = $folderInfo['play_count'] ?? 0;
                $photoCount = 0;
                $photoViews = 0;

                if ($guid && isset($guidToPhotoStats[$guid])) {
                    $photoCount = $guidToPhotoStats[$guid]['photo_count'];
                    $photoViews = $guidToPhotoStats[$guid]['total_views'];
                }

                $totalPhotos += $photoCount;
                $totalPhotoViews += $photoViews;

                $folders[] = [
                    'path' => $folderPath,
                    'name' => basename($folderPath),
                    'play_count' => $folderPlayCount,
                    'photo_count' => $photoCount,
                    'photo_views' => $photoViews,
                ];
            }
        }

        usort($folders, fn($a, $b) => $b['play_count'] - $a['play_count']);

        $stats['playlists'][] = [
            'path' => $playlistPath,
            'name' => $playlistName,
            'play_count' => $playCount,
            'folder_count' => count($folders),
            'total_photos' => $totalPhotos,
            'total_photo_views' => $totalPhotoViews,
            'folders' => $folders,
        ];
    }

    usort($stats['playlists'], fn($a, $b) => $b['play_count'] - $a['play_count']);

    return $stats;
}

/**
 * Mirror of sanitizePlaylistName() from slidefunctions.php.
 */
function sanitizePlaylistNamePHP(string $name): string {
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $sanitized = preg_replace('/_+/', '_', $sanitized);
    $sanitized = trim($sanitized, '_');
    return $sanitized;
}
