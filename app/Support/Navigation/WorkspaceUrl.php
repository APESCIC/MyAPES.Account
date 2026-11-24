<?php

namespace App\Support\Navigation;

/**
 * Same-origin checks for workspace chrome links (#1046).
 *
 * Laravel {@see route()} returns absolute URLs, so a naive `str_starts_with($url, 'http')`
 * wrongly treats Profile and Help as external.
 */
final class WorkspaceUrl
{
    public static function isExternal(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $urlHost !== '' && $appHost !== '' && $urlHost !== $appHost;
    }
}
