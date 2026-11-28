<?php

namespace App\Core\Settings;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Services\TemporaryPasswordService;

/**
 * Admin → Security → Sign-in policy (#947): temporary password TTL, phone PIN
 * length and whether staff must use 2FA. Stored in the shared settings store.
 */
final class SecuritySettings
{
    public const GROUP = 'security';

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        $define = static fn (string $key, string $type, mixed $default, array $rules): SettingDefinition => new SettingDefinition(self::GROUP, $key, $type, default: $default, rules: $rules);

        return [
            $define('temporary_password_ttl_hours', SettingDefinition::INTEGER, TemporaryPasswordService::TTL_HOURS, ['integer', 'min:1', 'max:720']),
            $define('phone_pin_min_length', SettingDefinition::INTEGER, PhoneVerificationPinService::MIN_LENGTH, ['integer', 'min:4', 'max:8']),
            $define('phone_pin_max_length', SettingDefinition::INTEGER, PhoneVerificationPinService::MAX_LENGTH, ['integer', 'min:4', 'max:8']),
            $define('staff_two_factor_required', SettingDefinition::BOOLEAN, false, ['boolean']),
        ];
    }

    public function temporaryPasswordTtlHours(): int
    {
        $hours = (int) $this->settings->get(self::GROUP, 'temporary_password_ttl_hours');

        return $hours > 0 ? $hours : TemporaryPasswordService::TTL_HOURS;
    }

    public function phonePinMinLength(): int
    {
        $min = (int) $this->settings->get(self::GROUP, 'phone_pin_min_length');

        return max(4, min(8, $min > 0 ? $min : PhoneVerificationPinService::MIN_LENGTH));
    }

    public function phonePinMaxLength(): int
    {
        $max = (int) $this->settings->get(self::GROUP, 'phone_pin_max_length');
        $max = $max > 0 ? $max : PhoneVerificationPinService::MAX_LENGTH;
        $min = $this->phonePinMinLength();

        return max($min, min(8, $max));
    }

    public function staffTwoFactorRequired(): bool
    {
        return (bool) $this->settings->get(self::GROUP, 'staff_two_factor_required');
    }
}
