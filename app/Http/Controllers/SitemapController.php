<?php

namespace App\Http\Controllers;

use App\Core\Settings\GeneralSettings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Plugins\Recruitment\Models\RecruitmentRole;

class SitemapController extends Controller
{
    public const CACHE_KEY = 'seo.sitemap.xml';

    public function __invoke(GeneralSettings $general): Response
    {
        $xml = Cache::remember(self::CACHE_KEY, now()->addMinutes(15), function () use ($general): string {
            $urls = [];

            foreach (array_filter([
                route('home'),
                route('privacy'),
                route('cookies'),
                route('help'),
                route('terms'),
                route('change-log.index'),
                route('public.login'),
                $general->registrationOpen() ? route('public.register') : null,
                route('staff.login'),
            ]) as $loc) {
                $urls[] = ['loc' => $loc, 'lastmod' => null];
            }

            try {
                $urls[] = [
                    'loc' => route('recruitment.index'),
                    'lastmod' => null,
                ];

                $roles = RecruitmentRole::query()
                    ->open()
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->get(['id', 'updated_at', 'published_at']);

                foreach ($roles as $role) {
                    $urls[] = [
                        'loc' => route('recruitment.show', $role),
                        'lastmod' => optional($role->updated_at ?? $role->published_at)->toAtomString(),
                    ];
                }
            } catch (\Throwable) {
                // Recruitment routes may be unavailable when the plugin is disabled.
            }

            $lines = [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            ];

            foreach ($urls as $entry) {
                $lines[] = '  <url>';
                $lines[] = '    <loc>'.e($entry['loc']).'</loc>';
                if (is_string($entry['lastmod']) && $entry['lastmod'] !== '') {
                    $lines[] = '    <lastmod>'.e($entry['lastmod']).'</lastmod>';
                }
                $lines[] = '  </url>';
            }

            $lines[] = '</urlset>';

            return implode("\n", $lines)."\n";
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
