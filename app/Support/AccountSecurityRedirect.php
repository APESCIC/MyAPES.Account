<?php

namespace App\Support;

final class AccountSecurityRedirect
{
    public static function security(string $fragment = ''): string
    {
        $url = route('account.security');

        return $fragment !== '' ? $url.'#'.ltrim($fragment, '#') : $url;
    }

    public static function fromIntended(?string $intended): string
    {
        if (! is_string($intended) || $intended === '') {
            return self::security();
        }

        $intendedPath = parse_url($intended, PHP_URL_PATH);
        $passwordUpdatePath = parse_url(route('profile.password.update'), PHP_URL_PATH);
        $phonePinPath = parse_url(route('profile.phone-pin.update'), PHP_URL_PATH);
        $usernameUpdatePath = parse_url(route('profile.username.update'), PHP_URL_PATH);
        $emailChangePath = parse_url(route('profile.email.change'), PHP_URL_PATH);
        $passkeyOptionsPath = parse_url(route('passkey.registration-options'), PHP_URL_PATH);
        $passkeyStorePath = parse_url(route('passkey.store'), PHP_URL_PATH);
        $logoutOthersPath = parse_url(route('account.security.sessions.destroy-others'), PHP_URL_PATH);

        if (is_string($passwordUpdatePath) && $intendedPath === $passwordUpdatePath) {
            return self::security('password');
        }

        if (is_string($phonePinPath) && $intendedPath === $phonePinPath) {
            return self::security('phone-pin');
        }

        if (is_string($usernameUpdatePath) && $intendedPath === $usernameUpdatePath) {
            return route('account.show').'#username';
        }

        if (is_string($emailChangePath) && $intendedPath === $emailChangePath) {
            return route('account.show').'#account-email';
        }

        if (is_string($logoutOthersPath) && $intendedPath === $logoutOthersPath) {
            return self::security('signed-in-devices');
        }

        if (
            (is_string($passkeyOptionsPath) && $intendedPath === $passkeyOptionsPath)
            || (is_string($passkeyStorePath) && $intendedPath === $passkeyStorePath)
            || (is_string($intendedPath) && str_starts_with($intendedPath, '/user/passkeys'))
        ) {
            return self::security('passkeys');
        }

        if (is_string($intendedPath) && str_starts_with($intendedPath, '/profile/two-factor')) {
            return self::security('two-factor');
        }

        return $intended;
    }
}