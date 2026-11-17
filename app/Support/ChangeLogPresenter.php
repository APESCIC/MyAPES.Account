<?php

namespace App\Support;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Support\Facades\Gate;

class ChangeLogPresenter
{
    private const GITHUB_RELEASE_TAG_URL = 'https://github.com/APESCIC/MyAPES-Account/releases/tag/v%s';

    public function __construct(private readonly ReleaseHistoryRepository $releases) {}

    public function viewerCanSeeInternalNotes(?User $user): bool
    {
        return $user instanceof User && Gate::forUser($user)->any([
            AuthorizationProfile::PERMISSION_STAFF_ACCESS,
            AuthorizationProfile::PERMISSION_VOLUNTEER_ACCESS,
            AuthorizationProfile::PERMISSION_STUDENT_ACCESS,
        ]);
    }

    /**
     * @return array{
     *     currentRelease: array<string, mixed>,
     *     releases: list<array<string, mixed>>,
     *     showInternalAudienceFilter: bool,
     *     showInternalNotes: bool,
     * }
     */
    public function viewData(?User $user): array
    {
        $showInternal = $this->viewerCanSeeInternalNotes($user);
        $current = $this->releases->current();
        $currentVersion = (string) ($current['version'] ?? $this->releases->version());

        if ($showInternal) {
            $releases = array_map(
                function (array $release) use ($currentVersion): array {
                    $annotated = self::withGithubReleaseReference($release);
                    $annotated['is_current'] = ((string) ($annotated['version'] ?? '')) === $currentVersion;

                    return $annotated;
                },
                $this->releases->all(),
            );

            return [
                'currentRelease' => self::withGithubReleaseReference($current),
                'releases' => $releases,
                'showInternalAudienceFilter' => true,
                'showInternalNotes' => true,
            ];
        }

        $releases = array_map(
            static function (array $release) use ($currentVersion): array {
                $release['is_current'] = ((string) ($release['version'] ?? '')) === $currentVersion;

                return $release;
            },
            self::forPublicAudience($this->releases->all()),
        );

        return [
            'currentRelease' => self::isPublicFacing($current)
                ? self::publicProjection($current)
                : self::publicCurrentPlaceholder($current),
            'releases' => $releases,
            'showInternalAudienceFilter' => false,
            'showInternalNotes' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     */
    public static function isPublicFacing(array $release): bool
    {
        return in_array('public-facing', $release['audiences'] ?? [], true);
    }

    /**
     * @param  list<array<string, mixed>>  $releases
     * @return list<array<string, mixed>>
     */
    public static function forPublicAudience(array $releases): array
    {
        $visible = [];

        foreach ($releases as $release) {
            if (! self::isPublicFacing($release)) {
                continue;
            }

            $visible[] = self::publicProjection($release);
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function publicProjection(array $release): array
    {
        $audiences = array_values(array_filter(
            is_array($release['audiences'] ?? null) ? $release['audiences'] : [],
            static fn (mixed $audience): bool => $audience === 'public-facing',
        ));

        return [
            'version' => $release['version'] ?? '',
            'date' => $release['date'] ?? '',
            'channel' => $release['channel'] ?? '',
            'type' => $release['type'] ?? '',
            'title' => $release['title'] ?? '',
            'summary' => $release['summary'] ?? '',
            'changes' => is_array($release['changes'] ?? null) ? $release['changes'] : [],
            'affected_areas' => is_array($release['affected_areas'] ?? null) ? $release['affected_areas'] : [],
            'categories' => is_array($release['categories'] ?? null) ? $release['categories'] : [],
            'audiences' => $audiences === [] ? ['public-facing'] : $audiences,
            'version_rationale' => $release['version_rationale'] ?? '',
            'validation' => [],
            'known_limitations' => [],
            'rollback' => '',
            'provenance' => '',
            'references' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function publicCurrentPlaceholder(array $release): array
    {
        return self::publicProjection([
            'version' => $release['version'] ?? '',
            'date' => $release['date'] ?? '',
            'channel' => $release['channel'] ?? '',
            'type' => $release['type'] ?? '',
            'title' => 'Current release',
            'summary' => '',
            'changes' => [],
            'affected_areas' => [],
            'categories' => [],
            'audiences' => ['public-facing'],
            'version_rationale' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function withGithubReleaseReference(array $release): array
    {
        $version = trim((string) ($release['version'] ?? ''));

        if ($version === '') {
            return $release;
        }

        $url = sprintf(self::GITHUB_RELEASE_TAG_URL, $version);
        $references = is_array($release['references'] ?? null) ? $release['references'] : [];

        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            if (($reference['url'] ?? '') === $url) {
                return $release;
            }
        }

        $release['references'] = [
            ...$references,
            [
                'label' => "GitHub Release v{$version}",
                'url' => $url,
            ],
        ];

        return $release;
    }
}
