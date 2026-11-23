<?php

namespace App\Support\Theme;

use InvalidArgumentException;

/**
 * Friendly critter illustrations for Glassy and Jungle themes (#900).
 * SVG is the primary asset; WebP fallbacks may be added later.
 */
final class CritterCast
{
    /** @var list<string> */
    public const POSES = ['welcome', 'thinking', 'oops'];

    /**
     * @var array<string, array{display: string, species: string}>
     */
    private const META = [
        'spike' => ['display' => 'Spike', 'species' => 'bearded dragon'],
        'cleo' => ['display' => 'Cleo', 'species' => 'chameleon'],
        'dot' => ['display' => 'Dot', 'species' => 'tree frog'],
        'speck' => ['display' => 'Speck', 'species' => 'crested gecko'],
        'moss' => ['display' => 'Moss', 'species' => 'tortoise'],
        'noodle' => ['display' => 'Noodle', 'species' => 'corn snake'],
        'rio' => ['display' => 'Rio', 'species' => 'parrot'],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::META);
    }

    /**
     * @return list<string>
     */
    public static function poses(): array
    {
        return self::POSES;
    }

    /**
     * @return array{display: string, species: string, poses: array<string, array{svg: string, webp: string|null, alt: string}>}
     */
    public static function critter(string $name): array
    {
        self::assertName($name);

        $meta = self::META[$name];
        $poses = [];

        foreach (self::POSES as $pose) {
            $poses[$pose] = self::asset($name, $pose);
        }

        return [
            'display' => $meta['display'],
            'species' => $meta['species'],
            'poses' => $poses,
        ];
    }

    /**
     * @return array<string, array{display: string, species: string, poses: array<string, array{svg: string, webp: string|null, alt: string}>}>
     */
    public static function all(): array
    {
        $cast = [];

        foreach (self::names() as $name) {
            $cast[$name] = self::critter($name);
        }

        return $cast;
    }

    /**
     * @return array{svg: string, webp: string|null, alt: string}
     */
    public static function resolve(string $name, string $pose = 'welcome'): array
    {
        self::assertName($name);
        self::assertPose($pose);

        return self::asset($name, $pose);
    }

    public static function svgPublicPath(string $name, string $pose): string
    {
        self::assertName($name);
        self::assertPose($pose);

        return public_path('mascot/cast/'.$name.'-'.$pose.'.svg');
    }

    /**
     * @return array{svg: string, webp: string|null, alt: string}
     */
    private static function asset(string $name, string $pose): array
    {
        $base = 'mascot/cast/'.$name.'-'.$pose;
        $webpPath = public_path($base.'.webp');

        return [
            'svg' => $base.'.svg',
            'webp' => is_file($webpPath) ? $base.'.webp' : null,
            'alt' => self::altText($name, $pose),
        ];
    }

    private static function altText(string $name, string $pose): string
    {
        $meta = self::META[$name];
        $display = $meta['display'];
        $species = $meta['species'];

        return match ($pose) {
            'welcome' => "{$display} the {$species} waving a cheerful welcome",
            'thinking' => "{$display} the {$species} looking thoughtful",
            'oops' => "{$display} the {$species} looking apologetic after a small mishap",
            default => "{$display} the {$species}",
        };
    }

    public static function assertName(string $name): void
    {
        if (! array_key_exists($name, self::META)) {
            throw new InvalidArgumentException("Unknown critter cast member [{$name}].");
        }
    }

    public static function assertPose(string $pose): void
    {
        if (! in_array($pose, self::POSES, true)) {
            throw new InvalidArgumentException("Unknown critter pose [{$pose}].");
        }
    }
}