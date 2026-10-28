<?php

$parseCsv = static function (?string $raw): array {
    if (! is_string($raw) || trim($raw) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
};

return [
    'oidc' => [
        'issuer' => env('OIDC_ISSUER', env('CLOUDRON_OIDC_ISSUER')),
        'client_id' => env('OIDC_CLIENT_ID', env('CLOUDRON_OIDC_CLIENT_ID')),
        'client_secret' => env('OIDC_CLIENT_SECRET', env('CLOUDRON_OIDC_CLIENT_SECRET')),
        'redirect_uri' => env(
            'OIDC_REDIRECT_URI',
            rtrim((string) env('CLOUDRON_APP_ORIGIN', ''), '/').'/staff/auth/callback'
        ),
        'scopes' => $parseCsv(env('OIDC_SCOPES', 'openid,profile,email')),
    ],

    'ldap' => [
        'host' => env('LDAP_HOST', env('CLOUDRON_LDAP_HOST')),
        'port' => (int) env('LDAP_PORT', env('CLOUDRON_LDAP_PORT', 389)),
        'base_dn' => env('LDAP_BASE_DN', env('CLOUDRON_LDAP_USERS_BASE_DN')),
        'groups_base_dn' => env('LDAP_GROUPS_BASE_DN', env('CLOUDRON_LDAP_GROUPS_BASE_DN')),
        'bind_dn' => env('LDAP_BIND_DN', env('CLOUDRON_LDAP_BIND_DN')),
        'bind_password' => env('LDAP_BIND_PASSWORD', env('CLOUDRON_LDAP_BIND_PASSWORD')),
        'user_filter' => env('LDAP_USER_FILTER', '(mail=%s)'),
        'group_attribute' => env('LDAP_GROUP_ATTRIBUTE', 'memberOf'),
        'start_tls' => (bool) env('LDAP_START_TLS', false),
        'connect_timeout_seconds' => max(
            1,
            min(30, (int) env('LDAP_CONNECT_TIMEOUT_SECONDS', 5)),
        ),
        'search_timeout_seconds' => max(
            1,
            min(60, (int) env('LDAP_SEARCH_TIMEOUT_SECONDS', 10)),
        ),
    ],

    'directory' => [
        'group_prefix' => strtolower((string) env('DIRECTORY_GROUP_PREFIX', 'myapesaccount.')),
        'required_groups' => [
            'myapesaccount.staff',
            'myapesaccount.admin',
            'myapesaccount.superadmin',
            'myapesaccount.volunteer',
            'myapesaccount.student',
        ],
        'revalidate_seconds' => (int) env('LDAP_SESSION_REVALIDATE_SECONDS', 300),
        'revalidate_in_local' => (bool) env('LDAP_SESSION_REVALIDATE_IN_LOCAL', false),
        'sync_lock_seconds' => (int) env('DIRECTORY_SYNC_LOCK_SECONDS', 300),
    ],

    'audit' => [
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 180),
    ],

    'mail' => [
        'log_retention_days' => (int) env('MAIL_LOG_RETENTION_DAYS', 90),
    ],

    'consent' => [
        'policy_version' => env('CONTACT_CONSENT_POLICY_VERSION', '2026-08'),
        'privacy_notice_url' => env('PRIVACY_NOTICE_URL'),
    ],

    'github' => [
        'repository_url' => env('MYAPES_GITHUB_REPOSITORY_URL', 'https://github.com/APESCIC/MyAPES-Account'),
        'new_issue_url' => env('MYAPES_GITHUB_NEW_ISSUE_URL', 'https://github.com/APESCIC/MyAPES-Account/issues/new/choose'),
        'discussions_url' => env('MYAPES_GITHUB_DISCUSSIONS_URL', 'https://github.com/APESCIC/MyAPES-Account/discussions'),
    ],
];
