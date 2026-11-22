<?php

namespace App\Core\Push;

use App\Core\Settings\AppSetting;
use App\Core\Settings\AppSettings;
use App\Core\Settings\SettingDefinition;
use Illuminate\Database\QueryException;

/**
 * Admin pause switch for all web pushes (#803). Read without cache so pause
 * takes effect within one minute everywhere.
 */
final class PushSettings
{
    public const GROUP = 'push';

    public const KEY_PAUSED = 'paused';

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinition(
                self::GROUP,
                self::KEY_PAUSED,
                SettingDefinition::BOOLEAN,
                default: false,
                rules: ['boolean'],
            ),
        ];
    }

    public function paused(): bool
    {
        try {
            $value = AppSetting::query()
                ->where('group', self::GROUP)
                ->where('key', self::KEY_PAUSED)
                ->value('value');
        } catch (QueryException) {
            return false;
        }

        return $value === '1';
    }

    public function setPaused(bool $paused, ?int $userId): void
    {
        $this->settings->set(self::GROUP, [self::KEY_PAUSED => $paused], $userId);
    }
}