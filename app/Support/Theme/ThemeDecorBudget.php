<?php

namespace App\Support\Theme;

/**
 * Decorative asset budget for Glassy and Jungle themes (#906).
 */
final class ThemeDecorBudget
{
    public const MAX_BYTES_PER_PAGE = 150_000;

    /**
     * Jungle-only SVG backgrounds (canopy, leaf, vine).
     *
     * @return list<string>
     */
    public static function jungleDecorRelativePaths(): array
    {
        return [
            'decor/jungle/canopy.svg',
            'decor/jungle/leaf-corner.svg',
            'decor/jungle/vine-divider.svg',
        ];
    }

    /**
     * Critter cast SVGs on a representative heavy signed-in workspace page
     * (menu chrome critter plus an in-content empty-state tip).
     *
     * @return list<string>
     */
    public static function representativePageCastPaths(): array
    {
        return [
            'mascot/cast/spike-welcome.svg',
            'mascot/cast/dot-thinking.svg',
        ];
    }

    public static function glassyDecorativeBytes(): int
    {
        return self::sumBytes(self::representativePageCastPaths());
    }

    public static function jungleDecorativeBytes(): int
    {
        return self::sumBytes([
            ...self::representativePageCastPaths(),
            ...self::jungleDecorRelativePaths(),
        ]);
    }

    /**
     * @param  list<string>  $relativePublicPaths
     */
    public static function sumBytes(array $relativePublicPaths): int
    {
        $total = 0;

        foreach (array_values(array_unique($relativePublicPaths)) as $relativePath) {
            $fullPath = public_path($relativePath);
            if (! is_file($fullPath)) {
                continue;
            }

            $total += (int) filesize($fullPath);
        }

        return $total;
    }
}