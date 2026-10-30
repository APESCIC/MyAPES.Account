<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolve layout SEO meta for public pages (#273).
 */
final class SeoMeta
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $keywords,
        public readonly string $canonical,
        public readonly string $ogUrl,
        public readonly string $ogLocale,
        public readonly string $siteName,
        public readonly string $ogImage,
        public readonly bool $noindex,
        public readonly string $ogType = 'website',
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     description?: string|null,
     *     keywords?: string|null,
     *     noindex?: bool|null,
     *     type?: string|null
     * }  $overrides
     */
    public static function fromRequest(Request $request, array $overrides = []): self
    {
        $title = self::nonEmpty($overrides['title'] ?? null)
            ?? __('seo.default.title');
        $description = self::nonEmpty($overrides['description'] ?? null)
            ?? __('seo.default.description');
        $keywords = self::nonEmpty($overrides['keywords'] ?? null)
            ?? __('seo.default.keywords');
        $noindex = array_key_exists('noindex', $overrides) && is_bool($overrides['noindex'])
            ? $overrides['noindex']
            : self::shouldNoindex($request);

        // Noindex responses (signed-in, admin, password, verify) must not embed
        // request-path identifiers in canonical/og:url — keeps anti-enumeration
        // 403 bodies identical across existing vs unknown IDs.
        $canonical = $noindex
            ? url('/')
            : self::canonicalUrl($request);

        return new self(
            title: self::withProductSuffix($title),
            description: Str::limit(trim(strip_tags($description)), 155, ''),
            keywords: $keywords,
            canonical: $canonical,
            ogUrl: $canonical,
            ogLocale: str_replace('-', '_', (string) app()->getLocale()),
            siteName: __('seo.default.site_name'),
            ogImage: asset('social/og-image-1200x630.jpg'),
            noindex: $noindex,
            ogType: self::nonEmpty($overrides['type'] ?? null) ?? 'website',
        );
    }

    public static function shouldNoindex(Request $request): bool
    {
        if ($request->user() !== null) {
            return true;
        }

        return $request->routeIs(
            'password.*',
            'verification.*',
            'admin.*',
            'superadmin.*',
        );
    }

    public static function canonicalUrl(Request $request): string
    {
        return url($request->path() === '/' ? '/' : '/'.$request->path());
    }

    public static function truncateDescription(?string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');

        if ($clean === '') {
            return __('seo.default.description');
        }

        return Str::limit($clean, 155, '');
    }

    public static function withProductSuffix(string $title): string
    {
        $productName = __('terms.app_name');
        $suffix = ' | '.$productName;

        if ($title === $productName || str_ends_with($title, $suffix)) {
            return $title;
        }

        return $title.$suffix;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
