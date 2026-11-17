<?php

namespace App\Support\Releases;

use App\Models\Release;
use DateTimeImmutable;
use FilesystemIterator;
use JsonException;

/**
 * Validates one changelog/releases/vX.Y.Z.json file (#705).
 */
class ReleaseEntryValidator
{
    /**
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        'version',
        'date',
        'channel',
        'type',
        'title',
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
    ];

    /**
     * @var list<string>
     */
    public const OPTIONAL_FIELDS = [
        'previous_version',
        'is_current',
        'is_published',
        'source_checksum',
        'source_path',
        'slug',
    ];

    /** @var list<string> */
    public const CHANNELS = ['stable', 'beta'];

    /** @var list<string> */
    public const TYPES = ['major', 'minor', 'patch'];

    /** @var list<string> */
    public const CATEGORIES = [
        'added',
        'changed',
        'fixed',
        'removed',
        'security',
        'compliance',
        'accessibility',
    ];

    /** @var list<string> */
    public const AUDIENCES = ['public-facing', 'internal-only'];

    /**
     * @return list<string>
     */
    public function validateFile(string $path): array
    {
        if (! is_file($path)) {
            return ["File not found: {$path}"];
        }

        $basename = basename($path);
        if (str_starts_with($basename, '_')) {
            return ["Template files are not release entries: {$basename}"];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ["Unable to read: {$path}"];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ["Invalid JSON in {$basename}: ".$e->getMessage()];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return ["{$basename} must decode to a JSON object."];
        }

        /** @var array<string, mixed> $decoded */
        return $this->validateEntry($decoded, $basename);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    public function validateEntry(array $entry, ?string $filename = null): array
    {
        $errors = [];
        $allowedFields = [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS];

        foreach (array_diff(array_keys($entry), $allowedFields) as $field) {
            $errors[] = "Release entry contains unsupported field [{$field}].";
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $entry) || $this->isEmpty($entry[$field])) {
                $errors[] = "Release entry is missing required field [{$field}].";
            }
        }

        $version = is_string($entry['version'] ?? null) ? trim($entry['version']) : '';

        if (! $this->isSemanticVersion($version)) {
            $errors[] = 'Release entry has an invalid semantic version.';
        }

        $releaseDate = is_string($entry['date'] ?? null) ? trim($entry['date']) : '';

        if (! $this->isIsoDate($releaseDate)) {
            $errors[] = 'Release entry has an invalid ISO release date.';
        }

        if (! in_array($entry['channel'] ?? null, self::CHANNELS, true)) {
            $errors[] = 'Release entry has an unsupported channel.';
        }

        if (! in_array($entry['type'] ?? null, self::TYPES, true)) {
            $errors[] = 'Release entry has an unsupported version type.';
        }

        if (! $this->containsOnly($entry['categories'] ?? null, self::CATEGORIES)) {
            $errors[] = 'Release entry has unsupported categories.';
        }

        if (! $this->containsOnly($entry['audiences'] ?? null, self::AUDIENCES)) {
            $errors[] = 'Release entry has unsupported audiences.';
        }

        $this->validateStringLists($entry, $errors);
        $this->validateReferences($entry['references'] ?? null, $errors);
        $this->validateFilename($filename, $version, $errors);

        if (array_key_exists('previous_version', $entry)
            && $entry['previous_version'] !== null
            && $entry['previous_version'] !== ''
            && ! $this->isSemanticVersion((string) $entry['previous_version'])) {
            $errors[] = 'Release entry has an invalid previous_version.';
        }

        if (array_key_exists('slug', $entry) && $entry['slug'] !== null && $entry['slug'] !== '') {
            $expectedSlug = $this->isSemanticVersion($version)
                ? Release::slugFromVersion($version)
                : null;

            if ($expectedSlug !== null && $entry['slug'] !== $expectedSlug) {
                $errors[] = "Release entry slug must match version encoding [{$expectedSlug}].";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validatePayload(array $payload, ?string $basename = null): array
    {
        return $this->validateEntry($payload, $basename);
    }

    /**
     * @return list<string>
     */
    public function validateDirectory(string $directory): array
    {
        $errors = [];
        $versions = [];
        $slugs = [];

        if (! is_dir($directory)) {
            return ["Release changelog directory is missing [{$directory}]."];
        }

        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                continue;
            }

            $basename = $fileInfo->getFilename();

            if (str_starts_with($basename, '_')) {
                continue;
            }

            $errors = [...$errors, ...$this->validateFile($fileInfo->getPathname())];

            $raw = file_get_contents($fileInfo->getPathname());
            if (! is_string($raw)) {
                continue;
            }

            try {
                $entry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $version = is_string($entry['version'] ?? null) ? trim($entry['version']) : '';

            if ($this->isSemanticVersion($version)) {
                if (isset($versions[$version])) {
                    $errors[] = 'Release versions must be unique across changelog files.';
                }

                $versions[$version] = $basename;

                $slug = Release::slugFromVersion($version);

                if (isset($slugs[$slug])) {
                    $errors[] = "Release slugs must be unique; [{$slug}] is duplicated.";
                }

                $slugs[$slug] = $basename;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        $errors = $this->validateFile($path);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(PHP_EOL, $errors));
        }

        $raw = file_get_contents($path);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $version) === 1;
    }

    private function isIsoDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function containsOnly(mixed $values, array $allowed): bool
    {
        if (! is_array($values) || $values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return count(array_unique($values)) === count($values);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $errors
     */
    private function validateStringLists(array $entry, array &$errors): void
    {
        foreach (['changes', 'affected_areas', 'validation', 'known_limitations'] as $field) {
            $values = $entry[$field] ?? null;

            if (! is_array($values) || $values === []) {
                continue;
            }

            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "Release entry field [{$field}] must contain non-empty strings.";

                    break;
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateReferences(mixed $references, array &$errors): void
    {
        if (! is_array($references) || $references === []) {
            return;
        }

        foreach ($references as $reference) {
            if (! is_array($reference)
                || ! is_string($reference['label'] ?? null)
                || trim($reference['label']) === ''
                || ! is_string($reference['url'] ?? null)
                || filter_var($reference['url'], FILTER_VALIDATE_URL) === false
                || ! str_starts_with($reference['url'], 'https://github.com/APESCIC/MyAPES-Account/')) {
                $errors[] = 'Release entry has an invalid reference.';

                break;
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateFilename(?string $filename, string $version, array &$errors): void
    {
        if ($filename === null || $filename === '') {
            return;
        }

        if (! $this->isSemanticVersion($version)) {
            return;
        }

        $expected = Release::filenameForVersion($version);

        if ($filename !== $expected) {
            $errors[] = "Release filename must be [{$expected}] for version [{$version}].";
        }
    }
}