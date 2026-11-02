<?php

namespace App\Services;

use App\Exceptions\AuthReadinessException;
use App\Exceptions\DirectoryUnavailable;
use App\Support\DirectoryGroupPrefix;
use App\Support\DirectoryImmutableMappings;

class AuthReadinessChecker
{
    private const ALWAYS_MEMBERED_GROUPS = [
        'myapesaccount.admin',
        'myapesaccount.staff',
    ];

    public function __construct(
        private readonly OidcDiscoveryValidator $discovery,
        private readonly LdapGroupResolver $directory,
    ) {}

    public function check(): int
    {
        $issuer = $this->requiredString('issuer');
        $this->requiredString('client_id');
        $this->requiredString('client_secret');
        $redirectUri = $this->requiredString('redirect_uri');

        $expectedRedirect = rtrim((string) config('app.url'), '/').'/staff/auth/callback';

        if (! hash_equals($expectedRedirect, $redirectUri)) {
            throw new AuthReadinessException('oidc_configuration', 'redirect_uri_mismatch');
        }

        $scopes = config('myapes.oidc.scopes', []);

        if (! is_array($scopes) || array_diff(['openid', 'profile', 'email'], $scopes) !== []) {
            throw new AuthReadinessException('oidc_configuration', 'required_scope_missing');
        }

        $this->discovery->validate($issuer);

        $groups = $this->configuredGroups();
        $required = array_keys(DirectoryImmutableMappings::all());
        sort($required);

        if ($groups !== $required) {
            throw new AuthReadinessException('ldap_groups', 'expected_required_groups');
        }

        try {
            $catalogue = $this->directory->enumerateGroups();
        } catch (DirectoryUnavailable) {
            throw new AuthReadinessException('ldap_directory', 'unavailable');
        }

        $catalogue = collect($catalogue)->keyBy('name');

        foreach ($required as $group) {
            $entry = $catalogue->get($group);

            if (! is_array($entry)) {
                throw new AuthReadinessException(
                    'ldap_groups',
                    'required_group_missing',
                );
            }
        }

        foreach (self::ALWAYS_MEMBERED_GROUPS as $group) {
            $entry = $catalogue->get($group);

            if (! is_array($entry) || ($entry['member_count'] ?? 0) < 1) {
                throw new AuthReadinessException(
                    'ldap_groups',
                    'required_group_empty',
                );
            }
        }

        return count($groups);
    }

    /**
     * @return list<string>
     */
    private function configuredGroups(): array
    {
        $groups = config('myapes.directory.required_groups', []);

        if (! is_array($groups)) {
            return [];
        }

        $filtered = DirectoryGroupPrefix::filterGroups($groups);
        sort($filtered);

        return $filtered;
    }

    private function requiredString(string $key): string
    {
        $value = config("myapes.oidc.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new AuthReadinessException('oidc_configuration', "missing_{$key}");
        }

        return trim($value);
    }
}
