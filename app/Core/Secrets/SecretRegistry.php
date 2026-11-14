<?php

namespace App\Core\Secrets;

use App\Core\Settings\AppSetting;
use App\Core\Settings\AppSettings;
use App\Core\Settings\SettingDefinition;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Shared registry for write-only secrets (#808, #810). A value entered in
 * Admin → Keys and secrets wins over the .env fallback; clearing the admin
 * value falls back to .env when present.
 */
final class SecretRegistry
{
    public const GROUP = 'keys_and_secrets';

    public const SOURCE_KEYS = 'keys_and_secrets';

    public const SOURCE_ENV = 'env';

    public const SOURCE_MISSING = 'missing';

    public const INBOUND_MAIL_IMAP_PASSWORD = 'inbound_mail_imap_password';

    /** @var array<string, SecretDefinition> */
    private array $secrets = [];

    public function __construct(private readonly AppSettings $settings) {}

    public function register(SecretDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->secrets[$definition->key] = $definition;
            $this->settings->define(new SettingDefinition(
                self::GROUP,
                $definition->key,
                SettingDefinition::STRING,
                secret: true,
                env: $definition->env,
                default: null,
                rules: ['string', 'max:500'],
            ));
        }
    }

    /**
     * @return array<string, SecretDefinition>
     */
    public function all(): array
    {
        return $this->secrets;
    }

    public function has(string $key): bool
    {
        return isset($this->secrets[$key]);
    }

    public function definition(string $key): SecretDefinition
    {
        return $this->secrets[$key] ?? throw new InvalidArgumentException(sprintf('Unknown secret %s.', $key));
    }

    /**
     * Keys and secrets first, then the .env fallback, else null.
     */
    public function get(string $key): ?string
    {
        $this->definition($key);

        $stored = $this->storedValue($key);

        if ($stored !== null) {
            return $stored;
        }

        return $this->envValue($key);
    }

    /**
     * @return self::SOURCE_*
     */
    public function source(string $key): string
    {
        $this->definition($key);

        if ($this->storedValue($key) !== null) {
            return self::SOURCE_KEYS;
        }

        return $this->envValue($key) !== null ? self::SOURCE_ENV : self::SOURCE_MISSING;
    }

    private function storedValue(string $key): ?string
    {
        $value = $this->settings->getStored(self::GROUP, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, #[SensitiveParameter] string $value, ?int $userId = null): void
    {
        $this->definition($key);
        $this->settings->set(self::GROUP, [$key => $value], $userId);
    }

    public function clear(string $key): void
    {
        $this->definition($key);
        $this->settings->forget(self::GROUP, $key);
    }

    /**
     * @return array{source: string, set_at: ?Carbon, set_by: ?int}
     */
    public function status(string $key): array
    {
        $source = $this->source($key);
        $row = $source === self::SOURCE_KEYS
            ? AppSetting::query()->where('group', self::GROUP)->where('key', $key)->first()
            : null;

        return [
            'source' => $source,
            'set_at' => $row?->updated_at,
            'set_by' => $row?->updated_by,
        ];
    }

    private function envValue(string $key): ?string
    {
        $definition = $this->definition($key);

        if ($definition->env === null) {
            return null;
        }

        $value = config('app-settings.env.'.$definition->env);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
