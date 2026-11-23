<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemeSnapshotManifest;
use Tests\TestCase;

class ThemeSnapshotManifestTest extends TestCase
{
    public function test_matrix_expects_forty_theme_snapshots(): void
    {
        $filenames = ThemeSnapshotManifest::expectedFilenames();

        $this->assertCount(40, $filenames);
        $this->assertCount(40, array_unique($filenames));
    }

    public function test_committed_snapshots_match_the_matrix(): void
    {
        $directory = ThemeSnapshotManifest::snapshotDirectory();

        $this->assertDirectoryExists($directory);

        foreach (ThemeSnapshotManifest::expectedFilenames() as $filename) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;

            $this->assertFileExists($path, "Missing theme snapshot: {$filename}");

            $handle = fopen($path, 'rb');
            $this->assertNotFalse($handle, "Unreadable theme snapshot: {$filename}");
            $header = fread($handle, 8);
            fclose($handle);

            $this->assertSame("\x89PNG\r\n\x1a\n", $header, "{$filename} is not a PNG.");
            $this->assertGreaterThan(67, filesize($path), "{$filename} looks like an empty placeholder.");
        }
    }
}