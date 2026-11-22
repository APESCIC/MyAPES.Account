<?php

namespace App\Core\Push;

use Illuminate\Support\Facades\URL;

/**
 * Resolves push click URLs to same-origin paths for the service worker (#799).
 */
final class PushSameOriginPath
{
    public static function fromRoute(string $routeName, array $routeParameters = []): string
    {
        $absolute = route($routeName, $routeParameters, absolute: true);
        $parsed = parse_url($absolute);

        if (! is_array($parsed)) {
            return '/';
        }

        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? '#'.$parsed['fragment'] : '';

        return $path.$query.$fragment;
    }

    public static function absoluteFromRoute(string $routeName, array $routeParameters = []): string
    {
        return URL::to(self::fromRoute($routeName, $routeParameters));
    }
}