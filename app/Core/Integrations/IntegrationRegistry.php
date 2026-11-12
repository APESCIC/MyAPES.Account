<?php

namespace App\Core\Integrations;

use App\Core\Settings\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Every integration and its settings in one place (#634), on top of the shared
 * settings store (#732): a non-empty .env value wins, secrets are encrypted at
 * rest, and a secret is only ever described by its last four characters.
 */
final class IntegrationRegistry
{
    public const STATUS_NOT_SET = 'not_set';

    public const STATUS_ENV = 'env';

    public const STATUS_SET = 'set';

    private const HINT_LENGTH = 4;

    private const HINT_MIN_SECRET_LENGTH = 12;

    private const TEST_CACHE_PREFIX = 'integrations:last-test:';

    private const TEST_KEEP_DAYS = 30;

    /** @var array<string, Integration> */
    private array $integrations = [];

    public function __construct(private readonly AppSettings $settings) {}

    public function register(Integration ...$integrations): void
    {
        foreach ($integrations as $integration) {
            $this->integrations[$integration->key] = $integration;
            $this->settings->define(...$integration->settingDefinitions());
        }
    }

    /**
     * @return array<string, Integration>
     */
    public function all(): array
    {
        return $this->integrations;
    }

    public function has(string $key): bool
    {
        return isset($this->integrations[$key]);
    }

    public function get(string $key): Integration
    {
        return $this->integrations[$key] ?? throw new InvalidArgumentException(sprintf('Unknown integration %s.', $key));
    }

    public function value(string $integration, string $field): mixed
    {
        $definition = $this->get($integration);
        $definition->field($field);

        return $this->settings->get($definition->group(), $field);
    }

    public function overriddenByEnv(string $integration, string $field): bool
    {
        $definition = $this->get($integration);
        $definition->field($field);

        return $this->settings->overriddenByEnv($definition->group(), $field);
    }

    /**
     * Whether every required field has a value, from .env or the database.
     */
    public function configured(string $integration): bool
    {
        return $this->status($integration) !== self::STATUS_NOT_SET;
    }

    /**
     * @return self::STATUS_*
     */
    public function status(string $integration): string
    {
        $definition = $this->get($integration);
        $fromEnv = false;

        foreach ($definition->fields as $key => $field) {
            if (! $field->required) {
                continue;
            }

            $value = $this->settings->get($definition->group(), $key);
            if ($value === null || $value === '') {
                return self::STATUS_NOT_SET;
            }

            $fromEnv = $fromEnv || $this->settings->overriddenByEnv($definition->group(), $key);
        }

        return $fromEnv ? self::STATUS_ENV : self::STATUS_SET;
    }

    /**
     * The last four characters of a saved secret, so admins can tell keys apart.
     * Short secrets get no hint, because four characters would give too much away.
     */
    public function secretHint(string $integration, string $field): ?string
    {
        $definition = $this->get($integration);
        if (! $definition->field($field)->secret) {
            throw new InvalidArgumentException(sprintf('%s.%s is not a secret.', $integration, $field));
        }

        $value = $this->settings->get($definition->group(), $field);
        if (! is_string($value) || mb_strlen($value) < self::HINT_MIN_SECRET_LENGTH) {
            return null;
        }

        return mb_substr($value, -self::HINT_LENGTH);
    }

    /**
     * Saves the submitted fields that .env does not set. A blank secret keeps
     * the saved one. Returns the keys that changed.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function save(string $integration, array $values, ?int $userId = null): array
    {
        $definition = $this->get($integration);
        $values = array_filter(
            array_intersect_key($values, $definition->fields),
            fn (string $key): bool => ! $this->settings->overriddenByEnv($definition->group(), $key),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->settings->set($definition->group(), $values, $userId);
    }

    public function testable(string $integration): bool
    {
        return $this->get($integration)->tester !== null;
    }

    /**
     * Runs the integration's connection test and remembers the result:
     * null when it passed, otherwise a short reason.
     */
    public function test(string $integration): ?string
    {
        $tester = $this->get($integration)->tester
            ?? throw new InvalidArgumentException(sprintf('Integration %s has no connection test.', $integration));

        $error = $tester($this);
        Cache::put(self::TEST_CACHE_PREFIX.$integration, ['ok' => $error === null, 'error' => $error, 'at' => now()->toIso8601String()], now()->addDays(self::TEST_KEEP_DAYS));

        return $error;
    }

    /**
     * @return array{ok: bool, error: ?string, at: Carbon}|null
     */
    public function lastTest(string $integration): ?array
    {
        $this->get($integration);
        $test = Cache::get(self::TEST_CACHE_PREFIX.$integration);
        if (! is_array($test)) {
            return null;
        }

        return ['ok' => (bool) $test['ok'], 'error' => $test['error'], 'at' => Carbon::parse($test['at'])];
    }
}
