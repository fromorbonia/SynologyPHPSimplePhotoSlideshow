<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/statsfunctions.php';

class StatsFunctionsTest extends TestCase
{
    private $testDir;
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slideshow_stats_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->testDir = realpath($this->testDir);

        $this->tempDir = $this->testDir . DIRECTORY_SEPARATOR . 'temp';
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->testDir)) {
            $this->deleteDirectory($this->testDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeJsonFile(string $path, array $content): void
    {
        file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT));
    }

    public function testBuildStatsPayloadAggregatesPlaylistFolderAndPhotoStats(): void
    {
        $playlistsIndex = [
            '/photo/family' => [
                'name' => 'Family/Trips 2024',
                'play_count' => 7,
            ],
            '/photo/other' => [
                'name' => 'Other',
                'play_count' => 3,
            ],
        ];
        $this->writeJsonFile($this->tempDir . DIRECTORY_SEPARATOR . 'playlists_index.json', $playlistsIndex);

        // sanitizePlaylistNamePHP('Family/Trips 2024') => 'Family_Trips_2024'
        $this->writeJsonFile(
            $this->tempDir . DIRECTORY_SEPARATOR . 'playlist-Family_Trips_2024-index.json',
            [
                '/photo/family/A' => [
                    'play_count' => 2,
                    'guid' => '11111111-1111-1111-1111-111111111111',
                ],
                '/photo/family/B' => [
                    'play_count' => 5,
                    'guid' => '22222222-2222-2222-2222-222222222222',
                ],
            ]
        );

        $this->writeJsonFile(
            $this->tempDir . DIRECTORY_SEPARATOR . 'folderpics-11111111-1111-1111-1111-111111111111-index.json',
            [
                '/photo/family/A/p1.jpg' => ['play_count' => 3, 'city' => 'York'],
                '/photo/family/A/p2.jpg' => ['play_count' => 1, 'city' => 'York'],
            ]
        );

        $this->writeJsonFile(
            $this->tempDir . DIRECTORY_SEPARATOR . 'folderpics-22222222-2222-2222-2222-222222222222-index.json',
            [
                '/photo/family/B/p3.jpg' => ['play_count' => 9, 'city' => 'Leeds'],
            ]
        );

        $stats = buildStatsPayload($this->tempDir);

        $this->assertArrayHasKey('generated_at', $stats);
        $this->assertArrayHasKey('errors', $stats);
        $this->assertArrayHasKey('playlists', $stats);
        $this->assertEmpty($stats['errors']);
        $this->assertCount(2, $stats['playlists']);

        // Sorted by playlist play_count desc
        $this->assertSame('Family/Trips 2024', $stats['playlists'][0]['name']);
        $this->assertSame(7, $stats['playlists'][0]['play_count']);

        $family = $stats['playlists'][0];
        $this->assertSame(2, $family['folder_count']);
        $this->assertSame(3, $family['total_photos']);
        $this->assertSame(13, $family['total_photo_views']);

        // Folders sorted by folder play_count desc: B (5) then A (2)
        $this->assertCount(2, $family['folders']);
        $this->assertSame('B', $family['folders'][0]['name']);
        $this->assertSame(5, $family['folders'][0]['play_count']);
        $this->assertSame(1, $family['folders'][0]['photo_count']);
        $this->assertSame(9, $family['folders'][0]['photo_views']);

        $this->assertSame('A', $family['folders'][1]['name']);
        $this->assertSame(2, $family['folders'][1]['play_count']);
        $this->assertSame(2, $family['folders'][1]['photo_count']);
        $this->assertSame(4, $family['folders'][1]['photo_views']);

        // Playlist without index file should still exist with zero folder/photo totals
        $other = $stats['playlists'][1];
        $this->assertSame('Other', $other['name']);
        $this->assertSame(0, $other['folder_count']);
        $this->assertSame(0, $other['total_photos']);
        $this->assertSame(0, $other['total_photo_views']);
        $this->assertSame([], $other['folders']);
    }

    public function testBuildStatsPayloadReturnsErrorForMissingTempDir(): void
    {
        $missingDir = $this->testDir . DIRECTORY_SEPARATOR . 'does_not_exist';

        $stats = buildStatsPayload($missingDir);

        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('Temp directory not found', $stats['errors'][0]);
        $this->assertSame([], $stats['playlists']);
    }

    public function testBuildStatsPayloadReturnsErrorWhenPlaylistsIndexMissing(): void
    {
        $stats = buildStatsPayload($this->tempDir);

        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('playlists_index.json not found', $stats['errors'][0]);
        $this->assertSame([], $stats['playlists']);
    }

    public function testBuildStatsPayloadReturnsErrorWhenPlaylistsIndexInvalid(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'playlists_index.json', '{invalid json');

        $stats = buildStatsPayload($this->tempDir);

        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('Could not parse playlists_index.json.', $stats['errors'][0]);
        $this->assertSame([], $stats['playlists']);
    }

    public function testSanitizePlaylistNamePHPMatchesExpectedFilenameRules(): void
    {
        $this->assertSame('Family_Trips_2024', sanitizePlaylistNamePHP('Family/Trips 2024'));
        $this->assertSame('my_playlist_name', sanitizePlaylistNamePHP('___my@@playlist  name___'));
        $this->assertSame('Already-Ok_Name', sanitizePlaylistNamePHP('Already-Ok_Name'));
    }
}
