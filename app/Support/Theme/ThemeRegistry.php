<?php

namespace App\Support\Theme;

use InvalidArgumentException;

/**
 * Named workspace skins (Glassy, Jungle) and light/dark modes from config/themes.php (#975).
 */
final class ThemeRegistry
{
    /**
     * @return array<string, array{label: string, css: string}>
     */
    public static function skins(): array
    {
        /** @var array<string, array{label: string, css: string}> $skins */
        $skins = config('themes.skins', []);

        return $skins;
    }

    public static function hasSkin(string $skin): bool
    {
        return array_key_exists($skin, self::skins());
    }

    public static function label(string $skin): string
    {
        self::assertSkin($skin);

        return self::skins()[$skin]['label'];
    }

    public static function defaultSkin(): string
    {
        return (string) config('themes.default_skin', 'glassy');
    }

    public static function defaultMode(): string
    {
        return (string) config('themes.default_mode', 'light');
    }

    public static function assertSkin(string $skin): void
    {
        if (! self::hasSkin($skin)) {
            throw new InvalidArgumentException("Unknown theme skin [{$skin}].");
        }
    }

    public static function assertMode(string $mode): void
    {
        /** @var list<string> $modes */
        $modes = config('themes.modes', ['light', 'dark']);

        if (! in_array($mode, $modes, true)) {
            throw new InvalidArgumentException("Unknown theme mode [{$mode}].");
        }
    }
}