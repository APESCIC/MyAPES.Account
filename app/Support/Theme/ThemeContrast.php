<?php

namespace App\Support\Theme;

/**
 * WCAG 2.2 relative luminance and contrast for theme token pairs (#898).
 */
final class ThemeContrast
{
    public const AA_NORMAL_TEXT = 4.5;

    public const AA_LARGE_OR_UI = 3.0;

    public static function contrastRatio(string $foreground, string $background): float
    {
        $l1 = self::relativeLuminance($foreground);
        $l2 = self::relativeLuminance($background);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function relativeLuminance(string $color): float
    {
        [$red, $green, $blue] = self::rgbComponents($color);

        return 0.2126 * self::linearise($red)
            + 0.7152 * self::linearise($green)
            + 0.0722 * self::linearise($blue);
    }

    /**
     * Blend an rgba() colour over an opaque hex background.
     */
    public static function compositeRgbaOn(string $foregroundRgba, string $backgroundHex): string
    {
        $foreground = self::parseRgba($foregroundRgba);
        $background = self::parseHex($backgroundHex);

        $alpha = $foreground['alpha'];
        $red = (int) round($alpha * $foreground['red'] + (1 - $alpha) * $background['red']);
        $green = (int) round($alpha * $foreground['green'] + (1 - $alpha) * $background['green']);
        $blue = (int) round($alpha * $foreground['blue'] + (1 - $alpha) * $background['blue']);

        return self::toHex($red, $green, $blue);
    }

    public static function meetsAaNormalText(float $ratio): bool
    {
        return $ratio >= self::AA_NORMAL_TEXT;
    }

    public static function meetsAaLargeOrUi(float $ratio): bool
    {
        return $ratio >= self::AA_LARGE_OR_UI;
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: float}
     */
    public static function parseRgba(string $color): array
    {
        $trimmed = strtolower(trim($color));

        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i', $trimmed, $matches) !== 1) {
            throw new \InvalidArgumentException("Unsupported rgba colour [{$color}].");
        }

        return [
            'red' => self::channel((int) $matches[1]),
            'green' => self::channel((int) $matches[2]),
            'blue' => self::channel((int) $matches[3]),
            'alpha' => isset($matches[4]) ? max(0.0, min(1.0, (float) $matches[4])) : 1.0,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgbComponents(string $color): array
    {
        $trimmed = strtolower(trim($color));

        if (str_starts_with($trimmed, 'rgba') || str_starts_with($trimmed, 'rgb')) {
            $rgba = self::parseRgba($trimmed);

            return [
                $rgba['red'] / 255,
                $rgba['green'] / 255,
                $rgba['blue'] / 255,
            ];
        }

        $hex = self::parseHex($trimmed);

        return [$hex['red'] / 255, $hex['green'] / 255, $hex['blue'] / 255];
    }

    /**
     * @return array{red: int, green: int, blue: int}
     */
    private static function parseHex(string $color): array
    {
        $hex = ltrim(strtolower(trim($color)), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-f]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException("Unsupported hex colour [{$color}].");
        }

        return [
            'red' => hexdec(substr($hex, 0, 2)),
            'green' => hexdec(substr($hex, 2, 2)),
            'blue' => hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function toHex(int $red, int $green, int $blue): string
    {
        return sprintf('#%02x%02x%02x', self::channel($red), self::channel($green), self::channel($blue));
    }

    private static function channel(int $value): int
    {
        return max(0, min(255, $value));
    }

    private static function linearise(float $channel): float
    {
        return $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
}