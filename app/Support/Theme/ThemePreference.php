<?php

namespace App\Support\Theme;

use App\Core\Accounts\User;
use App\Core\Settings\GeneralSettings;

/**
 * Resolves workspace appearance for signed-in accounts and persists choices (#977).
 */
final class ThemePreference
{
    public const GUEST_COOKIE = 'myapes-appearance';

    public function __construct(private readonly GeneralSettings $generalSettings) {}

    /**
     * @return array{skin: string, mode: string, calm: bool}
     */
    public function resolve(?User $user): array
    {
        if ($user === null) {
            return [
                'skin' => ThemeRegistry::defaultSkin(),
                'mode' => ThemeRegistry::defaultMode(),
                'calm' => false,
            ];
        }

        return [
            'skin' => $this->resolveSkin($user),
            'mode' => $this->resolveMode($user),
            'calm' => $this->resolveCalm($user),
        ];
    }

    public function saveForUser(User $user, string $skin, string $mode, bool $calm): void
    {
        ThemeRegistry::assertSkin($skin);
        ThemeRegistry::assertMode($mode);

        $user->forceFill([
            'theme_skin' => $skin,
            'theme_mode' => $mode,
            'theme_calm' => $calm,
        ])->save();
    }

    /**
     * Copies a one-off guest cookie choice when the account has never saved a skin (#977).
     */
    public function mergeGuestCookieOnce(User $user, ?string $rawCookie): void
    {
        if ($user->theme_skin !== null) {
            return;
        }

        if (! is_string($rawCookie) || trim($rawCookie) === '') {
            return;
        }

        $decoded = json_decode($rawCookie, true);
        if (! is_array($decoded)) {
            return;
        }

        $skin = isset($decoded['skin']) && is_string($decoded['skin']) ? $decoded['skin'] : null;
        $mode = isset($decoded['mode']) && is_string($decoded['mode']) ? $decoded['mode'] : null;
        $calm = array_key_exists('calm', $decoded) ? (bool) $decoded['calm'] : false;

        if ($skin === null || ! ThemeRegistry::hasSkin($skin)) {
            return;
        }

        if ($mode === null || ! in_array($mode, config('themes.modes', ['light', 'dark']), true)) {
            $mode = ThemeRegistry::defaultMode();
        }

        $this->saveForUser($user, $skin, $mode, $calm);
    }

    private function resolveSkin(User $user): string
    {
        if (is_string($user->theme_skin) && $user->theme_skin !== '') {
            return $user->theme_skin;
        }

        return $this->generalSettings->defaultThemeSkin();
    }

    private function resolveMode(User $user): string
    {
        if (is_string($user->theme_mode) && $user->theme_mode !== '') {
            return $user->theme_mode;
        }

        return ThemeRegistry::defaultMode();
    }

    private function resolveCalm(User $user): bool
    {
        if ($user->theme_calm === null) {
            return false;
        }

        return (bool) $user->theme_calm;
    }
}