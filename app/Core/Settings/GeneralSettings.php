<?php

namespace App\Core\Settings;

/**
 * The general options admins edit on Admin → Settings → General (#734),
 * stored in the shared settings store (#732). The registration switch and
 * the soft banner take effect on the public site (#739).
 */
final class GeneralSettings
{
    public const GROUP = 'general';

    public const BANNER_MAX_LENGTH = 500;

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        $define = static fn (string $key, string $type, mixed $default, array $rules): SettingDefinition => new SettingDefinition(self::GROUP, $key, $type, default: $default, rules: $rules);

        return [
            $define('support_email', SettingDefinition::STRING, null, ['email:rfc', 'max:255']),
            $define('support_phone', SettingDefinition::STRING, null, ['string', 'max:50', 'regex:/^\+?[0-9 ()-]+$/']),
            $define('registration_enabled', SettingDefinition::BOOLEAN, true, ['boolean']),
            $define('banner_enabled', SettingDefinition::BOOLEAN, false, ['boolean']),
            $define('banner_text', SettingDefinition::STRING, null, ['string', 'max:'.self::BANNER_MAX_LENGTH]),
        ];
    }

    public function registrationOpen(): bool
    {
        return (bool) $this->settings->get(self::GROUP, 'registration_enabled');
    }

    /**
     * The banner text to show, or null when the banner is off or has no text.
     */
    public function banner(): ?string
    {
        if (! $this->settings->get(self::GROUP, 'banner_enabled')) {
            return null;
        }

        $text = trim((string) $this->settings->get(self::GROUP, 'banner_text'));

        return $text === '' ? null : $text;
    }

    public function supportEmail(): ?string
    {
        return $this->settings->get(self::GROUP, 'support_email');
    }

    public function supportPhone(): ?string
    {
        return $this->settings->get(self::GROUP, 'support_phone');
    }
}
