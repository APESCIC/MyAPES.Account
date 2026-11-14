<?php

namespace App\Core\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The shared admin settings store (#732). Reading a setting gives its
 * non-empty .env value first, then the stored value, then its default.
 * Secrets are encrypted at rest and in the cache, and never logged.
 */
final class AppSettings
{
    public const SOURCE_ENV = 'env';

    public const SOURCE_DATABASE = 'database';

    public const SOURCE_DEFAULT = 'default';

    /** @var array<string, array<string, SettingDefinition>> */
    private array $definitions = [];

    public function define(SettingDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->group][$definition->key] = $definition;
        }
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function definitions(string $group): array
    {
        return $this->definitions[$group] ?? [];
    }

    public function definition(string $group, string $key): SettingDefinition
    {
        return $this->definitions[$group][$key] ?? throw new InvalidArgumentException(sprintf('Unknown setting %s.%s.', $group, $key));
    }

    public function get(string $group, string $key): mixed
    {
        $definition = $this->definition($group, $key);

        return match ($this->source($group, $key)) {
            self::SOURCE_ENV => $definition->cast($this->envValue($definition)),
            self::SOURCE_DATABASE => $definition->cast($this->storedValue($definition)),
            default => $definition->default,
        };
    }

    /**
     * @return self::SOURCE_*
     */
    public function source(string $group, string $key): string
    {
        $definition = $this->definition($group, $key);

        if ($this->envValue($definition) !== null) {
            return self::SOURCE_ENV;
        }

        return $this->storedValue($definition) !== null ? self::SOURCE_DATABASE : self::SOURCE_DEFAULT;
    }

    public function overriddenByEnv(string $group, string $key): bool
    {
        return $this->source($group, $key) === self::SOURCE_ENV;
    }

    /**
     * Whether a value is stored in the database, for example a saved secret.
     */
    public function stored(string $group, string $key): bool
    {
        return $this->storedValue($this->definition($group, $key)) !== null;
    }

    /**
     * The value stored in the database only, ignoring any .env override.
     * Used when an admin-entered secret must win over .env (#808, #810).
     */
    public function getStored(string $group, string $key): mixed
    {
        $definition = $this->definition($group, $key);
        $stored = $this->storedValue($definition);

        return $stored !== null ? $definition->cast($stored) : null;
    }

    /**
     * Saves submitted values for one group. A blank secret keeps the stored one;
     * a blank non-secret value clears it. Returns the keys that changed.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function set(string $group, array $values, ?int $userId = null): array
    {
        $definitions = array_intersect_key($this->definitions($group), $values);
        Validator::make(
            $values,
            array_map(fn (SettingDefinition $definition): array => ['nullable', ...$definition->rules], $definitions),
        )->validate();

        $rows = $this->rows($group);
        $changed = [];

        foreach ($definitions as $key => $definition) {
            $value = $definition->serialise($values[$key]);

            if ($definition->secret && $value === null) {
                continue;
            }

            $current = $this->decode($definition, $rows[$key] ?? null);
            if ($current === $value) {
                continue;
            }

            AppSetting::query()->updateOrCreate(
                ['group' => $group, 'key' => $key],
                [
                    'value' => $value !== null && $definition->secret ? Crypt::encryptString($value) : $value,
                    'is_secret' => $definition->secret,
                    'updated_by' => $userId,
                ],
            );
            $changed[] = $key;
        }

        if ($changed !== []) {
            $this->flush($group);
        }

        return $changed;
    }

    public function forget(string $group, string $key): void
    {
        $this->definition($group, $key);
        AppSetting::query()->where('group', $group)->where('key', $key)->delete();
        $this->flush($group);
    }

    public function flush(string $group): void
    {
        Cache::forget($this->cacheKey($group));
    }

    private function envValue(SettingDefinition $definition): mixed
    {
        if ($definition->env === null) {
            return null;
        }

        $value = config('app-settings.env.'.$definition->env);

        return $value === null || $value === '' ? null : $value;
    }

    private function storedValue(SettingDefinition $definition): ?string
    {
        return $this->decode($definition, $this->rows($definition->group)[$definition->key] ?? null);
    }

    private function decode(SettingDefinition $definition, ?string $stored): ?string
    {
        if ($stored === null || ! $definition->secret) {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::warning('A stored secret setting could not be decrypted; it is treated as not set.', [
                'setting' => $definition->group.'.'.$definition->key,
            ]);

            return null;
        }
    }

    /**
     * Stored values for a group, as held in the database (secrets still encrypted).
     *
     * @return array<string, string|null>
     */
    private function rows(string $group): array
    {
        $cached = Cache::get($this->cacheKey($group));
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $rows = AppSetting::query()->where('group', $group)->pluck('value', 'key')->all();
        } catch (QueryException) {
            // Before the app_settings migration has run (for example during a deploy), nothing is stored yet.
            return [];
        }

        Cache::put($this->cacheKey($group), $rows, max(1, (int) config('app-settings.cache_seconds', 3600)));

        return $rows;
    }

    private function cacheKey(string $group): string
    {
        return 'app-settings:'.$group;
    }
}
