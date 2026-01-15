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
        $result = configGet($this->testConfigFile);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('photoExt', $result);
        $this->assertArrayHasKey('transitionTime', $result);
        $this->assertEquals('jpg', $result['photoExt']);
        $this->assertEquals(5000, $result['transitionTime']);
    }

    public function testConfigGetReturnsNullForInvalidJson()
    {
        $invalidFile = $this->testDir . DIRECTORY_SEPARATOR . 'invalid.json';
        file_put_contents($invalidFile, '{invalid json}');
        
        $result = configGet($invalidFile);
        
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
        
        $result = playlistPick($playlistMap, $playlist);
        
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
        for ($i = 0; $i < 20; $i++) {
            $result = playlistPick($playlistMap, $playlist);
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
}