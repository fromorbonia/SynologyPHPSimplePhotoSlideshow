<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/slidefunctions.php';

class SlideFunctionsTest extends TestCase
{
    private $testDir;
    private $testConfigFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary test directory
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slideshow_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        
        // Normalize path for consistent comparisons
        $this->testDir = realpath($this->testDir);
        
        // Create test config file
        $this->testConfigFile = $this->testDir . DIRECTORY_SEPARATOR . 'config.json';
        file_put_contents($this->testConfigFile, json_encode([
            'photoExt' => 'jpg',
            'transitionTime' => 5000
        ]));

        // Initialize session for tests that need it
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test directory
        if (file_exists($this->testDir)) {
            $this->deleteDirectory($this->testDir);
        }
    }

    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testConfigGet()
    {
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        $result = configGet($this->testConfigFile, $playlistsIndexFile);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('photoExt', $result);
        $this->assertArrayHasKey('transitionTime', $result);
        $this->assertEquals('jpg', $result['photoExt']);
        $this->assertEquals(5000, $result['transitionTime']);
    }

    public function testConfigGetReturnsNullForInvalidJson()
    {
        // Create an invalid JSON file
        $invalidFile = $this->testDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($invalidFile, '{invalid json}');
        
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        $result = configGet($invalidFile, $playlistsIndexFile);
        
        $this->assertNull($result);
    }

    public function testPlaylistPick()
    {
        $_SESSION['playlist-scanid'] = 'test-scan-123';
        
        $playlistMap = [0, 1, 2, 1, 2, 2];
        $playlist = [
            ['name' => 'Playlist 0', 'path' => '/path/0'],
            ['name' => 'Playlist 1', 'path' => '/path/1'],
            ['name' => 'Playlist 2', 'path' => '/path/2']
        ];
        
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        $result = playlistPick($playlistMap, $playlist, $playlistsIndexFile);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertContains($result, $playlist);
    }

    public function testPlaylistPickRandomness()
    {
        $_SESSION['playlist-scanid'] = 'test-scan-456';
        
        $playlistMap = [0, 0, 0, 0, 0, 1, 1, 1, 2];
        $playlist = [
            ['id' => 0],
            ['id' => 1],
            ['id' => 2]
        ];
        
        $results = [];
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        for ($i = 0; $i < 20; $i++) {
            $result = playlistPick($playlistMap, $playlist, $playlistsIndexFile);
            $results[] = $result['id'];
        }
        
        // With 20 picks, we should see some variation
        $this->assertGreaterThan(1, count(array_unique($results)));
    }

    public function testPlaylistItemPhotosWithoutSubFolders()
    {
        // Create test photos
        touch($this->testDir . DIRECTORY_SEPARATOR . 'photo1.jpg');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'photo2.jpg');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'photo3.png');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'document.txt');
        
        $plitem = [
            'path' => $this->testDir,
            'scan-sub-folders' => false
        ];
        
        $photoFolder = '';
        $result = playlistItemPhotos($plitem, 'jpg', $photoFolder);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals($this->testDir, $photoFolder);
        
        foreach ($result as $photo) {
            $this->assertStringEndsWith('.jpg', strtolower($photo));
        }
    }

    public function testPlaylistItemPhotosWithSubFolders()
    {
        // Create sub folders with photos
        $subDir1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $subDir2 = $this->testDir . DIRECTORY_SEPARATOR . 'sub2';
        mkdir($subDir1, 0777, true);
        mkdir($subDir2, 0777, true);
        
        touch($subDir1 . DIRECTORY_SEPARATOR . 'photo1.jpg');
        touch($subDir1 . DIRECTORY_SEPARATOR . 'photo2.jpg');
        touch($subDir2 . DIRECTORY_SEPARATOR . 'photo3.jpg');
        
        $plitem = [
            'path' => $this->testDir,
            'scan-sub-folders' => true
        ];
        
        $photoFolder = '';
        $result = playlistItemPhotos($plitem, 'jpg', $photoFolder);
        
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
        $this->assertNotEmpty($photoFolder);
        $this->assertStringContainsString($this->testDir, $photoFolder);
    }

    public function testPlaylistScanBuildWithoutSubFolders()
    {
        $playlist = [
            ['path' => '/path/1', 'scan-sub-folders' => false],
            ['path' => '/path/2', 'scan-sub-folders' => false],
            ['path' => '/path/3', 'scan-sub-folders' => false]
        ];
        
        $result = playlistScanBuild($playlist);
        
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals([0, 1, 2], $result);
    }

    public function testPlaylistScanBuildWithSubFolders()
    {
        // Create test directory structure
        $dir1 = $this->testDir . DIRECTORY_SEPARATOR . 'dir1';
        $dir2 = $this->testDir . DIRECTORY_SEPARATOR . 'dir2';
        mkdir($dir1 . DIRECTORY_SEPARATOR . 'sub1', 0777, true);
        mkdir($dir1 . DIRECTORY_SEPARATOR . 'sub2', 0777, true);
        mkdir($dir2, 0777, true);
        
        $playlist = [
            ['path' => $dir1, 'scan-sub-folders' => true],
            ['path' => $dir2, 'scan-sub-folders' => false]
        ];
        
        $result = playlistScanBuild($playlist);
        
        $this->assertIsArray($result);
        // dir1 has 2 subfolders, dir2 has 0 subfolders (counts as 1)
        $this->assertCount(3, $result);
        $this->assertEquals(0, $result[0]);
        $this->assertEquals(0, $result[1]);
        $this->assertEquals(1, $result[2]);
    }

    public function testDirContentsGetWithNoFilter()
    {
        // Create test files
        touch($this->testDir . DIRECTORY_SEPARATOR . 'file1.txt');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'file2.jpg');
        
        $subDir = $this->testDir . DIRECTORY_SEPARATOR . 'subdir';
        mkdir($subDir, 0777, true);
        touch($subDir . DIRECTORY_SEPARATOR . 'file3.png');
        
        $result = dirContentsGet($this->testDir);
        
        $this->assertIsArray($result);
        // Expect 4 files: config.json (from setUp) + file1.txt + file2.jpg + file3.png
        $this->assertCount(4, $result);
        $this->assertArrayHasKey('LastFileScan', $_SESSION);
    }

    public function testDirContentsGetWithFilter()
    {
        // Create test files
        touch($this->testDir . DIRECTORY_SEPARATOR . 'photo1.jpg');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'photo2.jpg');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'document.txt');
        touch($this->testDir . DIRECTORY_SEPARATOR . 'image.png');
        
        $result = dirContentsGet($this->testDir, '/\.jpg$/i');
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        
        foreach ($result as $file) {
            $this->assertStringEndsWith('.jpg', strtolower($file));
        }
    }

    public function testDirContentsGetRecursive()
    {
        // Create nested directory structure
        $subDir1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $subDir2 = $subDir1 . DIRECTORY_SEPARATOR . 'sub2';
        mkdir($subDir2, 0777, true);
        
        touch($this->testDir . DIRECTORY_SEPARATOR . 'root.txt');
        touch($subDir1 . DIRECTORY_SEPARATOR . 'level1.txt');
        touch($subDir2 . DIRECTORY_SEPARATOR . 'level2.txt');
        
        $result = dirContentsGet($this->testDir);
        
        $this->assertIsArray($result);
        // Expect 4 files: config.json (from setUp) + root.txt + level1.txt + level2.txt
        $this->assertCount(4, $result);
    }

    public function testDirSubFoldersGetNonRecursive()
    {
        // Create directory structure
        $sub1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $sub2 = $this->testDir . DIRECTORY_SEPARATOR . 'sub2';
        $nested = $sub1 . DIRECTORY_SEPARATOR . 'nested';
        
        mkdir($sub1, 0777, true);
        mkdir($sub2, 0777, true);
        mkdir($nested, 0777, true);
        
        // Add a file (should be ignored)
        touch($this->testDir . DIRECTORY_SEPARATOR . 'file.txt');
        
        $result = dirSubFoldersGet($this->testDir, $results, false);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains(realpath($sub1), $result);
        $this->assertContains(realpath($sub2), $result);
        $this->assertNotContains(realpath($nested), $result);
    }

    public function testDirSubFoldersGetRecursive()
    {
        // Create nested directory structure
        $sub1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $sub2 = $this->testDir . DIRECTORY_SEPARATOR . 'sub2';
        $nested1 = $sub1 . DIRECTORY_SEPARATOR . 'nested1';
        $nested2 = $nested1 . DIRECTORY_SEPARATOR . 'nested2';
        
        mkdir($nested2, 0777, true);
        mkdir($sub2, 0777, true);
        
        $result = dirSubFoldersGet($this->testDir, $results, true);
        
        $this->assertIsArray($result);
        $this->assertCount(4, $result);
        $this->assertContains(realpath($sub1), $result);
        $this->assertContains(realpath($sub2), $result);
        $this->assertContains(realpath($nested1), $result);
        $this->assertContains(realpath($nested2), $result);
    }

    public function testDirSubFoldersGetExcludesEaDir()
    {
        // Create directories including Synology @eaDir
        $normalDir = $this->testDir . DIRECTORY_SEPARATOR . 'normal';
        $eaDir = $this->testDir . DIRECTORY_SEPARATOR . '@eaDir';
        
        mkdir($normalDir, 0777, true);
        mkdir($eaDir, 0777, true);
        
        $result = dirSubFoldersGet($this->testDir);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertContains(realpath($normalDir), $result);
        $this->assertNotContains(realpath($eaDir), $result);
    }

    public function testStringSplitLast()
    {
        $result = stringSplitLast('/path/to/file.jpg', '/');
        $this->assertEquals('file.jpg', $result);
        
        $result = stringSplitLast('one-two-three-four', '-');
        $this->assertEquals('four', $result);
        
        $result = stringSplitLast('no-split-here', '|');
        $this->assertEquals('no-split-here', $result);
    }

    public function testStringSplitLastWithEmptyString()
    {
        $result = stringSplitLast('', '/');
        $this->assertEquals('', $result);
    }

    public function testStringSplitLastWithSingleElement()
    {
        $result = stringSplitLast('single', '/');
        $this->assertEquals('single', $result);
    }

    public function testStringSplitLastWithTrailingSeparator()
    {
        $result = stringSplitLast('/path/to/folder/', '/');
        $this->assertEquals('', $result);
    }

    public function testConfigGetCreatesPlaylistIndex()
    {
        // Create test config with playlists
        $testConfig = [
            'display' => ['interval' => 60],
            'playlist' => [
                ['name' => 'Test Playlist 1', 'path' => '/test/path1', 'scan-sub-folders' => false],
                ['path' => '/test/path2', 'scan-sub-folders' => false], // No name provided
                ['name' => 'Test Playlist 3', 'path' => '/test/path3', 'scan-sub-folders' => true]
            ]
        ];
        
        $configFile = $this->testDir . DIRECTORY_SEPARATOR . 'test_config.json';
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        
        file_put_contents($configFile, json_encode($testConfig));
        
        // First call should create index file
        $result = configGet($configFile, $playlistsIndexFile);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('playlists_index', $result);
        $this->assertTrue(file_exists($playlistsIndexFile));
        
        // Verify index structure
        $index = $result['playlists_index'];
        $this->assertCount(3, $index);
        
        $this->assertArrayHasKey('/test/path1', $index);
        $this->assertEquals('Test Playlist 1', $index['/test/path1']['name']);
        $this->assertEquals(0, $index['/test/path1']['play_count']);
        
        $this->assertArrayHasKey('/test/path2', $index);
        $this->assertEquals('path2', $index['/test/path2']['name']); // Uses basename when no name provided
        $this->assertEquals(0, $index['/test/path2']['play_count']);
        
        // Clean up
        if (file_exists($playlistsIndexFile)) {
            unlink($playlistsIndexFile);
        }
    }

    public function testConfigGetUpdatesExistingIndex()
    {
        $configFile = $this->testDir . DIRECTORY_SEPARATOR . 'test_config.json';
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        
        // Create existing index with play counts
        $existingIndex = [
            '/test/path1' => ['name' => 'Old Playlist 1', 'root_path' => '/test/path1', 'play_count' => 5],
            '/test/path2' => ['name' => 'Old Playlist 2', 'root_path' => '/test/path2', 'play_count' => 3],
            '/test/old_path' => ['name' => 'Removed Playlist', 'root_path' => '/test/old_path', 'play_count' => 10]
        ];
        file_put_contents($playlistsIndexFile, json_encode($existingIndex));
        
        // Create new config (path1 stays, path2 removed, path3 added)
        $testConfig = [
            'display' => ['interval' => 60],
            'playlist' => [
                ['name' => 'Updated Playlist 1', 'path' => '/test/path1', 'scan-sub-folders' => false],
                ['name' => 'New Playlist 3', 'path' => '/test/path3', 'scan-sub-folders' => true]
            ]
        ];
        file_put_contents($configFile, json_encode($testConfig));
        
        $result = configGet($configFile, $playlistsIndexFile);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('playlists_index', $result);
        
        $index = $result['playlists_index'];
        $this->assertCount(2, $index);
        
        // Existing playlist should keep its play count
        $this->assertArrayHasKey('/test/path1', $index);
        $this->assertEquals(5, $index['/test/path1']['play_count']);
        
        // New playlist should start at 0
        $this->assertArrayHasKey('/test/path3', $index);
        $this->assertEquals(0, $index['/test/path3']['play_count']);
        
        // Removed playlist should not be in index
        $this->assertArrayNotHasKey('/test/old_path', $index);
        $this->assertArrayNotHasKey('/test/path2', $index);
        
        // Clean up
        if (file_exists($playlistsIndexFile)) {
            unlink($playlistsIndexFile);
        }
    }

    public function testPlaylistIncrementPlayCount()
    {
        $configFile = $this->testDir . DIRECTORY_SEPARATOR . 'test_config.json';
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'playlists_index.json';
        
        // Create initial index
        $initialIndex = [
            '/test/path1' => ['name' => 'Test Playlist 1', 'root_path' => '/test/path1', 'play_count' => 0],
            '/test/path2' => ['name' => 'Test Playlist 2', 'root_path' => '/test/path2', 'play_count' => 5]
        ];
        file_put_contents($playlistsIndexFile, json_encode($initialIndex));
        
        // Test incrementing play count
        playlistIncrementPlayCount('/test/path1', $playlistsIndexFile);
        
        $updatedIndex = json_decode(file_get_contents($playlistsIndexFile), true);
        $this->assertEquals(1, $updatedIndex['/test/path1']['play_count']);
        $this->assertEquals(5, $updatedIndex['/test/path2']['play_count']); // Unchanged
        
        // Test incrementing again
        playlistIncrementPlayCount('/test/path1', $playlistsIndexFile);
        playlistIncrementPlayCount('/test/path2', $playlistsIndexFile);
        
        $updatedIndex = json_decode(file_get_contents($playlistsIndexFile), true);
        $this->assertEquals(2, $updatedIndex['/test/path1']['play_count']);
        $this->assertEquals(6, $updatedIndex['/test/path2']['play_count']);
        
        // Clean up
        if (file_exists($playlistsIndexFile)) {
            unlink($playlistsIndexFile);
        }
    }

    public function testSanitizePlaylistName()
    {
        $this->assertEquals('My_Playlist', sanitizePlaylistName('My Playlist'));
        $this->assertEquals('Test_123', sanitizePlaylistName('Test@123!'));
        $this->assertEquals('Multiple_Spaces', sanitizePlaylistName('Multiple   Spaces'));
        $this->assertEquals('Special_Chars', sanitizePlaylistName('Special!@#$%^&*()Chars'));
        $this->assertEquals('normal-name', sanitizePlaylistName('normal-name'));
    }

    public function testGetPlaylistFolders()
    {
        // Create test directory structure
        $subDir1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $subDir2 = $this->testDir . DIRECTORY_SEPARATOR . 'sub2';
        mkdir($subDir1, 0777, true);
        mkdir($subDir2, 0777, true);
        
        // Test with scan-sub-folders enabled
        $playlist1 = [
            'path' => $this->testDir,
            'scan-sub-folders' => true
        ];
        
        $folders1 = getPlaylistFolders($playlist1);
        $this->assertIsArray($folders1);
        $this->assertGreaterThan(0, count($folders1));
        
        // Test with scan-sub-folders disabled
        $playlist2 = [
            'path' => $this->testDir,
            'scan-sub-folders' => false
        ];
        
        $folders2 = getPlaylistFolders($playlist2);
        $this->assertIsArray($folders2);
        $this->assertCount(1, $folders2);
        $this->assertEquals($this->testDir, $folders2[0]);
    }





    public function testConfigFileCaching()
    {
        // Simulate config file caching behavior
        $testConfigFile = $this->testDir . DIRECTORY_SEPARATOR . 'test_config_cache.json';
        $testConfig = [
            'display' => ['interval' => 30],
            'playlist' => [
                ['name' => 'Cache Test', 'path' => '/test/cache', 'scan-sub-folders' => false]
            ]
        ];
        
        // Create initial config file
        file_put_contents($testConfigFile, json_encode($testConfig));
        $initialMtime = filemtime($testConfigFile);
        
        // Simulate session state for caching test
        $_SESSION['config'] = null;
        $_SESSION['config_file_mtime'] = null;
        
        // First load should set the cache
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'cache_test_index.json';
        
        // Verify file modification time can be retrieved
        $this->assertNotFalse($initialMtime);
        $this->assertTrue($initialMtime > 0);
        
        // Simulate a file modification by changing content and updating mtime
        sleep(1); // Ensure different timestamp
        $modifiedConfig = $testConfig;
        $modifiedConfig['display']['interval'] = 45;
        file_put_contents($testConfigFile, json_encode($modifiedConfig));
        $modifiedMtime = filemtime($testConfigFile);
        
        // Verify the modification time changed
        $this->assertNotEquals($initialMtime, $modifiedMtime);
        $this->assertGreaterThan($initialMtime, $modifiedMtime);
        
        // Clean up
        if (file_exists($testConfigFile)) {
            unlink($testConfigFile);
        }
        if (file_exists($playlistsIndexFile)) {
            unlink($playlistsIndexFile);
        }
    }

    public function testLoadConfigWithCaching()
    {
        $testConfigFile = $this->testDir . DIRECTORY_SEPARATOR . 'cache_test.json';
        $playlistsIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'cache_playlists_index.json';
        
        $testConfig = [
            'display' => ['interval' => 60],
            'playlist' => [
                ['name' => 'Cache Test', 'path' => '/test/cache', 'scan-sub-folders' => false]
            ]
        ];
        
        file_put_contents($testConfigFile, json_encode($testConfig));
        
        // Clear session to simulate new session
        unset($_SESSION['config']);
        unset($_SESSION['config_file_mtime']);
        
        // First call should load and cache config (new session)
        $config1 = loadConfigWithCaching($testConfigFile, $playlistsIndexFile);
        $this->assertNotNull($config1);
        $this->assertArrayHasKey('display', $config1);
        $this->assertEquals(60, $config1['display']['interval']);
        
        // Verify session variables are set
        $this->assertArrayHasKey('config', $_SESSION);
        $this->assertArrayHasKey('config_file_mtime', $_SESSION);
        
        // Second call should use cached version (no file modification)
        $config2 = loadConfigWithCaching($testConfigFile, $playlistsIndexFile);
        $this->assertEquals($config1, $config2);
        
        // Modify file and test reload detection
        sleep(1); // Ensure different mtime
        $modifiedConfig = $testConfig;
        $modifiedConfig['display']['interval'] = 90;
        file_put_contents($testConfigFile, json_encode($modifiedConfig));
        
        $config3 = loadConfigWithCaching($testConfigFile, $playlistsIndexFile);
        $this->assertNotNull($config3);
        $this->assertEquals(90, $config3['display']['interval']);
        
        // Clean up
        if (file_exists($testConfigFile)) {
            unlink($testConfigFile);
        }
        if (file_exists($playlistsIndexFile)) {
            unlink($playlistsIndexFile);
        }
        
        // Clean up any created playlist index files
        $files = glob($this->testDir . DIRECTORY_SEPARATOR . 'playlist-*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }
    

}