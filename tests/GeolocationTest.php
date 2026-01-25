<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/geolocation.php';

class GeolocationTest extends TestCase
{
    private $testDir;
    private $testIndexFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary test directory
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'geolocation_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        
        // Normalize path for consistent comparisons
        $this->testDir = realpath($this->testDir);
        
        // Create test index file
        $this->testIndexFile = $this->testDir . DIRECTORY_SEPARATOR . 'test-index.json';
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

    public function testGpsExifFractionToFloatWithString()
    {
        // Test fraction string conversion
        $result = gpsExifFractionToFloat('48/1');
        $this->assertEquals(48.0, $result);
        
        $result = gpsExifFractionToFloat('30/2');
        $this->assertEquals(15.0, $result);
        
        $result = gpsExifFractionToFloat('123456/10000');
        $this->assertEquals(12.3456, $result);
    }

    public function testGpsExifFractionToFloatWithNumeric()
    {
        $result = gpsExifFractionToFloat(48);
        $this->assertEquals(48.0, $result);
        
        $result = gpsExifFractionToFloat(12.5);
        $this->assertEquals(12.5, $result);
    }

    public function testGpsExifFractionToFloatWithInvalidInput()
    {
        // Invalid fraction format
        $result = gpsExifFractionToFloat('invalid');
        $this->assertNull($result);
        
        // Division by zero protection
        $result = gpsExifFractionToFloat('10/0');
        $this->assertNull($result);
    }

    public function testGpsExifToDecimalNorth()
    {
        // Paris coordinates: 48° 51' 24" N
        $coordinate = ['48/1', '51/1', '24/1'];
        $result = gpsExifToDecimal($coordinate, 'N');
        
        // Expected: 48 + (51/60) + (24/3600) ≈ 48.856667
        $this->assertEqualsWithDelta(48.856667, $result, 0.000001);
    }

    public function testGpsExifToDecimalSouth()
    {
        // Sydney coordinates: 33° 52' 10" S
        $coordinate = ['33/1', '52/1', '10/1'];
        $result = gpsExifToDecimal($coordinate, 'S');
        
        // Expected: -(33 + (52/60) + (10/3600)) ≈ -33.869444
        $this->assertEqualsWithDelta(-33.869444, $result, 0.000001);
    }

    public function testGpsExifToDecimalEast()
    {
        // Paris coordinates: 2° 21' 7" E
        $coordinate = ['2/1', '21/1', '7/1'];
        $result = gpsExifToDecimal($coordinate, 'E');
        
        // Expected: 2 + (21/60) + (7/3600) ≈ 2.351944
        $this->assertEqualsWithDelta(2.351944, $result, 0.000001);
    }

    public function testGpsExifToDecimalWest()
    {
        // New York coordinates: 74° 0' 21" W
        $coordinate = ['74/1', '0/1', '21/1'];
        $result = gpsExifToDecimal($coordinate, 'W');
        
        // Expected: -(74 + (0/60) + (21/3600)) ≈ -74.005833
        $this->assertEqualsWithDelta(-74.005833, $result, 0.000001);
    }

    public function testGpsExifToDecimalWithInvalidCoordinate()
    {
        $result = gpsExifToDecimal(['invalid', 'data'], 'N');
        $this->assertNull($result);
        
        $result = gpsExifToDecimal([], 'N');
        $this->assertNull($result);
    }

    public function testExtractGpsCoordinatesWithNoFile()
    {
        $result = extractGpsCoordinates('/non/existent/file.jpg');
        $this->assertNull($result);
    }

    public function testProcessPhotoGeolocationWithNoFile()
    {
        $result = processPhotoGeolocation('/non/existent/file.jpg');
        
        $this->assertIsArray($result);
        $this->assertNull($result['gps_lat']);
        $this->assertNull($result['gps_lon']);
        $this->assertEquals('no_gps_data', $result['geocode_status']);
    }

    public function testGetGeolocationStatusWithNonExistentFile()
    {
        $result = getGeolocationStatus('/non/existent/index.json');
        
        $this->assertEquals(0, $result['total_photos']);
        $this->assertEquals(0, $result['geocoded']);
        $this->assertEquals(0, $result['pending']);
        $this->assertEquals(0, $result['percent_complete']);
    }

    public function testGetGeolocationStatusWithEmptyIndex()
    {
        file_put_contents($this->testIndexFile, json_encode([]));
        
        $result = getGeolocationStatus($this->testIndexFile);
        
        $this->assertEquals(0, $result['total_photos']);
        $this->assertEquals(0, $result['percent_complete']);
    }

    public function testGetGeolocationStatusWithPendingPhotos()
    {
        $index = [
            '/path/to/photo1.jpg' => ['play_count' => 0],
            '/path/to/photo2.jpg' => ['play_count' => 0],
            '/path/to/photo3.jpg' => ['play_count' => 0]
        ];
        file_put_contents($this->testIndexFile, json_encode($index));
        
        $result = getGeolocationStatus($this->testIndexFile);
        
        $this->assertEquals(3, $result['total_photos']);
        $this->assertEquals(0, $result['geocoded']);
        $this->assertEquals(3, $result['pending']);
        $this->assertEquals(0, $result['percent_complete']);
    }

    public function testGetGeolocationStatusWithMixedPhotos()
    {
        $index = [
            '/path/to/photo1.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'completed',
                'country' => 'France',
                'city' => 'Paris'
            ],
            '/path/to/photo2.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'no_gps_data'
            ],
            '/path/to/photo3.jpg' => ['play_count' => 0],
            '/path/to/photo4.jpg' => ['play_count' => 0]
        ];
        file_put_contents($this->testIndexFile, json_encode($index));
        
        $result = getGeolocationStatus($this->testIndexFile);
        
        $this->assertEquals(4, $result['total_photos']);
        $this->assertEquals(1, $result['geocoded']);
        $this->assertEquals(1, $result['no_gps']);
        $this->assertEquals(2, $result['pending']);
        $this->assertEquals(50.0, $result['percent_complete']);
    }

    public function testGetGeolocationStatusWithAllCompleted()
    {
        $index = [
            '/path/to/photo1.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'completed',
                'country' => 'France',
                'city' => 'Paris'
            ],
            '/path/to/photo2.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'completed',
                'country' => 'Germany',
                'city' => 'Berlin'
            ]
        ];
        file_put_contents($this->testIndexFile, json_encode($index));
        
        $result = getGeolocationStatus($this->testIndexFile);
        
        $this->assertEquals(2, $result['total_photos']);
        $this->assertEquals(2, $result['geocoded']);
        $this->assertEquals(0, $result['pending']);
        $this->assertEquals(100.0, $result['percent_complete']);
    }

    public function testFindFolderPictureIndexFiles()
    {
        // Create some test index files
        file_put_contents($this->testDir . DIRECTORY_SEPARATOR . 'folderpics-abc123-index.json', '{}');
        file_put_contents($this->testDir . DIRECTORY_SEPARATOR . 'folderpics-def456-index.json', '{}');
        file_put_contents($this->testDir . DIRECTORY_SEPARATOR . 'playlist-test-index.json', '{}');
        
        $result = findFolderPictureIndexFiles($this->testDir);
        
        $this->assertCount(2, $result);
        $this->assertContains($this->testDir . DIRECTORY_SEPARATOR . 'folderpics-abc123-index.json', $result);
        $this->assertContains($this->testDir . DIRECTORY_SEPARATOR . 'folderpics-def456-index.json', $result);
    }

    public function testFindFolderPictureIndexFilesWithNoFiles()
    {
        $result = findFolderPictureIndexFiles($this->testDir);
        
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testUpdateIndexWithGeolocationSkipsAlreadyGeocoded()
    {
        $index = [
            '/path/to/photo1.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'completed',
                'country' => 'France',
                'city' => 'Paris'
            ],
            '/path/to/photo2.jpg' => [
                'play_count' => 0,
                'geocode_status' => 'completed',
                'country' => 'Germany',
                'city' => 'Berlin'
            ]
        ];
        file_put_contents($this->testIndexFile, json_encode($index, JSON_PRETTY_PRINT));
        
        $stats = updateIndexWithGeolocation($this->testIndexFile, 10);
        
        $this->assertEquals(2, $stats['already_geocoded']);
        $this->assertEquals(0, $stats['processed']);
    }

    public function testUpdateIndexWithGeolocationSkipsNonExistentFiles()
    {
        $index = [
            '/non/existent/photo1.jpg' => ['play_count' => 0],
            '/non/existent/photo2.jpg' => ['play_count' => 0]
        ];
        file_put_contents($this->testIndexFile, json_encode($index, JSON_PRETTY_PRINT));
        
        $stats = updateIndexWithGeolocation($this->testIndexFile, 10);
        
        $this->assertEquals(2, $stats['skipped']);
        $this->assertEquals(0, $stats['processed']);
    }

    public function testUpdateIndexWithGeolocationWithNonExistentFile()
    {
        $stats = updateIndexWithGeolocation('/non/existent/index.json', 10);
        
        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['skipped']);
    }

    public function testUpdateIndexWithGeolocationWithInvalidJson()
    {
        file_put_contents($this->testIndexFile, '{invalid json}');
        
        $stats = updateIndexWithGeolocation($this->testIndexFile, 10);
        
        $this->assertEquals(0, $stats['processed']);
    }
}
