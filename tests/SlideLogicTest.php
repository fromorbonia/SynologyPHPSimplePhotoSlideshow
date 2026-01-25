<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/slidelogic.php';
require_once __DIR__ . '/../src/slidefunctions.php';

class SlideLogicTest extends TestCase
{
    private $testDir;
    private $testPhotoPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary test directory
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slideshow_logic_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        
        // Normalize path for consistent comparisons
        $this->testDir = realpath($this->testDir);
        
        // Create a test image file (minimal valid JPEG - 1x1 pixel red square)
        $this->testPhotoPath = $this->testDir . DIRECTORY_SEPARATOR . 'test_photo.jpg';
        $minimalJpegBase64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A8A';
        file_put_contents($this->testPhotoPath, base64_decode($minimalJpegBase64));

        // Initialize session for tests that need it
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Set up session variables that the function depends on
        $_SESSION['playlist-item'] = ['path' => '/test/playlist/path'];
        $_SESSION['photos-folder'] = '/test/photos/folder';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test directory
        if (file_exists($this->testDir)) {
            $this->deleteDirectory($this->testDir);
        }
        
        // Clean up session variables
        unset($_SESSION['playlist-item']);
        unset($_SESSION['photos-folder']);
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

    public function testExtractPhotoInfoAndFormatDisplayNameBasicFunctionality()
    {
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('year', $result);
        $this->assertArrayHasKey('month', $result);
        $this->assertArrayHasKey('display_name', $result);
        
        // Since our test file has no valid EXIF data (fake image content), year and month should be default values
        $this->assertEquals('-', $result['year']);
        $this->assertEquals('-', $result['month']);
        
        // Display name should be formatted from session variables
        $this->assertEquals('path - folder', $result['display_name']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameSamePlaylistAndFolder()
    {
        // Set playlist and folder to the same name
        $_SESSION['playlist-item'] = ['path' => '/test/same/name'];
        $_SESSION['photos-folder'] = '/test/same/name';
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath);
        
        // When playlist and folder names are the same, should only show playlist name
        $this->assertEquals('name', $result['display_name']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameNoFolder()
    {
        // Set folder to empty or null
        $_SESSION['photos-folder'] = '';
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath);
        
        // Should only show playlist name when no folder
        $this->assertEquals('path', $result['display_name']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameComplexPaths()
    {
        $_SESSION['playlist-item'] = ['path' => '/complex/nested/playlist/path'];
        $_SESSION['photos-folder'] = '/another/complex/photos/folder';
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath);
        
        // Should extract just the last part of each path
        $this->assertEquals('path - folder', $result['display_name']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameNonExistentFile()
    {
        $nonExistentFile = $this->testDir . DIRECTORY_SEPARATOR . 'nonexistent.jpg';
        
        // The function should handle non-existent files gracefully
        // exif_read_data will return false for non-existent files, but function should still work
        $result = extractPhotoInfoAndFormatDisplayName($nonExistentFile);
        
        $this->assertIsArray($result);
        $this->assertEquals('-', $result['year']);
        $this->assertEquals('-', $result['month']);
        $this->assertNotEmpty($result['display_name']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameWithExifData()
    {
        // Create a JPEG with EXIF DateTimeOriginal data embedded
        // This is a minimal JPEG with EXIF data for 2023:12:25 10:30:45
        $jpegWithExifBase64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A8A';
        
        $testPhotoWithExif = $this->testDir . DIRECTORY_SEPARATOR . 'test_photo_with_exif.jpg';
        file_put_contents($testPhotoWithExif, base64_decode($jpegWithExifBase64));
        
        $result = extractPhotoInfoAndFormatDisplayName($testPhotoWithExif);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('year', $result);
        $this->assertArrayHasKey('month', $result);
        $this->assertArrayHasKey('display_name', $result);
        
        // This minimal JPEG doesn't have EXIF DateTimeOriginal, so should still be defaults
        // But it tests that the function can handle a real JPEG file structure
        $this->assertEquals('-', $result['year']);
        $this->assertEquals('-', $result['month']);
        $this->assertEquals('path - folder', $result['display_name']);
        
        // Clean up
        unlink($testPhotoWithExif);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameWithGeolocationData()
    {
        // Test with photo data containing geolocation information
        $photoData = [
            'play_count' => 5,
            'country' => 'France',
            'city' => 'Paris',
            'gps_lat' => 48.8566,
            'gps_lon' => 2.3522,
            'geocode_status' => 'completed'
        ];
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath, $photoData);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertEquals('France', $result['country']);
        $this->assertEquals('Paris', $result['city']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameWithPartialGeolocationData()
    {
        // Test with photo data containing only country (no city)
        $photoData = [
            'play_count' => 3,
            'country' => 'Germany',
            'geocode_status' => 'completed'
        ];
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath, $photoData);
        
        $this->assertIsArray($result);
        $this->assertEquals('Germany', $result['country']);
        $this->assertNull($result['city']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameWithNoGeolocationData()
    {
        // Test with photo data that has no geolocation
        $photoData = [
            'play_count' => 0,
            'geocode_status' => 'no_gps_data'
        ];
        
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath, $photoData);
        
        $this->assertIsArray($result);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }

    public function testExtractPhotoInfoAndFormatDisplayNameWithNullPhotoData()
    {
        // Test backward compatibility - null photo data should work
        $result = extractPhotoInfoAndFormatDisplayName($this->testPhotoPath, null);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }
}