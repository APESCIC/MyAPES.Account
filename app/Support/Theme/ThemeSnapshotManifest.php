<?php

namespace App\Support\Theme;

/**
 * Expected Playwright theme visual snapshots for issue #908.
 */
final class ThemeSnapshotManifest
{
    public static function matrixPath(): string
    {
        return base_path('resources/data/theme-snapshot-matrix.json');
    }

    /**
     * @return array{
     *     skins: list<string>,
     *     modes: list<string>,
     *     calm: list<string>,
     *     pages: list<array{key: string, path: string, auth: bool, prepare?: string, expectStatus?: int}>
     * }
     */
    public static function matrix(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(self::matrixPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new \RuntimeException('Theme snapshot matrix is not a JSON object.');
        }

        return $decoded;
    }

    public static function snapshotDirectory(): string
    {
        return base_path('tests/Browser/snapshots/theme');
    }

    public static function filename(string $page, string $skin, string $mode, bool $calm): string
    {
        return sprintf('%s-%s-%s-calm-%s.png', $page, $skin, $mode, $calm ? 'on' : 'off');
    }

    /**
     * @return list<string>
     */
    public static function expectedFilenames(): array
    {
        $matrix = self::matrix();
        $names = [];

        foreach ($matrix['pages'] as $page) {
            foreach ($matrix['skins'] as $skin) {
                foreach ($matrix['modes'] as $mode) {
                    foreach ($matrix['calm'] as $calmFlag) {
                        $names[] = self::filename(
                            $page['key'],
                            $skin,
                            $mode,
                            $calmFlag === 'on',
                        );
                    }
                }
            }
        }

        sort($names);

        return $names;
    }
}