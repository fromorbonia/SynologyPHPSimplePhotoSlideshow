<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/slidefunctions.php';

class PlaylistFolderIndexTest extends TestCase
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

    public function testCreateOrUpdatePlaylistFolderIndex()
    {
        // Create test directory structure
        $subDir1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        mkdir($subDir1, 0777, true);
        
        $_SESSION['playlist-scanid'] = 'test-scan-folder';
        
        $playlist = [
            'name' => 'Test Playlist',
            'path' => $this->testDir,
            'scan-sub-folders' => true
        ];
        
        $result = createOrUpdatePlaylistFolderIndex($playlist, 0, $this->testDir);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('file_name', $result);
        $this->assertArrayHasKey('folder_count', $result);
        
        $this->assertEquals('playlist-Test_Playlist-index.json', $result['file_name']);
        $this->assertTrue(file_exists($result['file_path']));
        
        // Verify file content
        $indexContent = json_decode(file_get_contents($result['file_path']), true);
        $this->assertIsArray($indexContent);
        $this->assertGreaterThan(0, count($indexContent));
        
        // Each entry should have play_count
        foreach ($indexContent as $folder => $info) {
            $this->assertArrayHasKey('play_count', $info);
            $this->assertEquals(0, $info['play_count']);
        }
        
        // Clean up
        if (file_exists($result['file_path'])) {
            unlink($result['file_path']);
        }
    }

    public function testIncrementPlaylistFolderCount()
    {
        $playlist = [
            'name' => 'Test Increment',
            'path' => $this->testDir,
            'scan-sub-folders' => false
        ];
        
        $_SESSION['playlist-scanid'] = 'test-scan-increment';
        
        // First create the index
        $result = createOrUpdatePlaylistFolderIndex($playlist, 0, $this->testDir);
        
        // Then increment the count
        incrementPlaylistFolderCount($playlist, $this->testDir, $this->testDir);
        
        // Verify the count was incremented
        $indexContent = json_decode(file_get_contents($result['file_path']), true);
        $this->assertEquals(1, $indexContent[$this->testDir]['play_count']);
        
        // Increment again
        incrementPlaylistFolderCount($playlist, $this->testDir, $this->testDir);
        
        $indexContent = json_decode(file_get_contents($result['file_path']), true);
        $this->assertEquals(2, $indexContent[$this->testDir]['play_count']);
        
        // Clean up
        if (file_exists($result['file_path'])) {
            unlink($result['file_path']);
        }
    }

    public function testPlaylistFolderIndexGuidGeneration()
    {
        $_SESSION['playlist-scanid'] = 'test-scan-guid';
        
        // Create test folder structure
        $subDir1 = $this->testDir . DIRECTORY_SEPARATOR . 'sub1';
        $subDir2 = $this->testDir . DIRECTORY_SEPARATOR . 'sub2';
        mkdir($subDir1, 0777, true);
        mkdir($subDir2, 0777, true);
        
        $playlist = [
            'name' => 'GUID Test Playlist',
            'path' => $this->testDir,
            'scan-sub-folders' => true
        ];
        
        // First call - should create new GUIDs
        $result1 = createOrUpdatePlaylistFolderIndex($playlist, 0, $this->testDir);
        
        // Check that index file was created
        $this->assertFileExists($result1['file_path']);
        
        // Read and verify index structure
        $indexData = file_get_contents($result1['file_path']);
        $index = json_decode($indexData, true);
        
        $this->assertIsArray($index);
        $this->assertCount(2, $index); // Should have 2 subfolders
        
        // Verify each folder has play_count and guid
        foreach ($index as $folderPath => $data) {
            $this->assertArrayHasKey('play_count', $data);
            $this->assertArrayHasKey('guid', $data);
            $this->assertEquals(0, $data['play_count']); // Initial count should be 0
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $data['guid']); // UUID v4 format
        }
        
        $originalGuids = [];
        foreach ($index as $folderPath => $data) {
            $originalGuids[$folderPath] = $data['guid'];
        }
        
        // Second call - should preserve existing GUIDs
        $result2 = createOrUpdatePlaylistFolderIndex($playlist, 0, $this->testDir);
        
        $indexData2 = file_get_contents($result2['file_path']);
        $index2 = json_decode($indexData2, true);
        
        // Verify GUIDs are preserved
        foreach ($index2 as $folderPath => $data) {
            $this->assertEquals($originalGuids[$folderPath], $data['guid'], "GUID should be preserved for folder: $folderPath");
        }
    }
}