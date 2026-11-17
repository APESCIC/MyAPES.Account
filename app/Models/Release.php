<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Database projection of a changelog/releases/vX.Y.Z.json entry (#705).
 */
class Release extends Model
{
    protected $fillable = [
        'version',
        'slug',
        'previous_version',
        'released_at',
        'channel',
        'type',
        'title',
        'is_current',
        'is_published',
        'summary',
        'changes',
        'affected_areas',
        'categories',
        'audiences',
        'version_rationale',
        'validation',
        'known_limitations',
        'rollback',
        'provenance',
        'references',
        'source_checksum',
        'source_path',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'date',
            'is_current' => 'boolean',
            'is_published' => 'boolean',
            'changes' => 'array',
            'affected_areas' => 'array',
            'categories' => 'array',
            'audiences' => 'array',
            'validation' => 'array',
            'known_limitations' => 'array',
            'references' => 'array',
        ];
    }

    /**
     * Distinct slug per semver component so v1.1.10 ≠ v1.11.0 (#705).
     */
    public static function slugFromVersion(string $version): string
    {
        $normalized = ltrim(trim($version), 'vV');

        if (preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $normalized) !== 1) {
            throw new \InvalidArgumentException("Invalid semantic version [{$version}].");
        }

        return 'release-v'.str_replace('.', '-', $normalized);
    }

    public static function filenameForVersion(string $version): string
    {
        $normalized = ltrim(trim($version), 'vV');

        return 'v'.$normalized.'.json';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function attributesFromEntry(array $entry): array
    {
        $version = is_string($entry['version'] ?? null) ? trim($entry['version']) : '';

        return [
            'version' => $version,
            'slug' => self::slugFromVersion($version),
            'previous_version' => is_string($entry['previous_version'] ?? null)
                ? trim($entry['previous_version'])
                : null,
            'released_at' => is_string($entry['date'] ?? null) ? trim($entry['date']) : null,
            'channel' => $entry['channel'] ?? null,
            'type' => $entry['type'] ?? null,
            'title' => $entry['title'] ?? null,
            'is_current' => (bool) ($entry['is_current'] ?? false),
            'is_published' => (bool) ($entry['is_published'] ?? false),
            'summary' => $entry['summary'] ?? null,
            'changes' => $entry['changes'] ?? [],
            'affected_areas' => $entry['affected_areas'] ?? [],
            'categories' => $entry['categories'] ?? [],
            'audiences' => $entry['audiences'] ?? [],
            'version_rationale' => $entry['version_rationale'] ?? null,
            'validation' => $entry['validation'] ?? [],
            'known_limitations' => $entry['known_limitations'] ?? [],
            'rollback' => $entry['rollback'] ?? null,
            'provenance' => $entry['provenance'] ?? null,
            'references' => $entry['references'] ?? [],
            'source_checksum' => is_string($entry['source_checksum'] ?? null)
                ? trim($entry['source_checksum'])
                : null,
            'source_path' => is_string($entry['source_path'] ?? null)
                ? trim($entry['source_path'])
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return [
            'version' => $this->version,
            'date' => $this->released_at?->format('Y-m-d') ?? '',
            'channel' => $this->channel,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'changes' => $this->changes ?? [],
            'affected_areas' => $this->affected_areas ?? [],
            'categories' => $this->categories ?? [],
            'audiences' => $this->audiences ?? [],
            'version_rationale' => $this->version_rationale,
            'validation' => $this->validation ?? [],
            'known_limitations' => $this->known_limitations ?? [],
            'rollback' => $this->rollback,
            'provenance' => $this->provenance,
            'references' => $this->references ?? [],
        ];
    }

    /**
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }
}
