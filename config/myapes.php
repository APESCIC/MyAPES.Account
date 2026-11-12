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
            'myapesaccount.volunteer',
            'myapesaccount.student',
        ],
        'revalidate_seconds' => (int) env('LDAP_SESSION_REVALIDATE_SECONDS', 300),
        'revalidate_in_local' => (bool) env('LDAP_SESSION_REVALIDATE_IN_LOCAL', false),
        'sync_lock_seconds' => (int) env('DIRECTORY_SYNC_LOCK_SECONDS', 300),
    ],

    'accounts' => [
        // Public registration and email change refuse these domains and their subdomains (#513).
        'staff_email_domains' => array_map(
            'strtolower',
            $parseCsv(env('MYAPES_STAFF_EMAIL_DOMAINS', 'apes.org.uk')),
        ),
    ],

    'audit' => [
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 180),
    ],

    'attachments' => [
        // Virus scanning for ticket and case uploads (#550): disabled, clamd or fake (tests only).
        'scanner' => env('ATTACHMENT_SCANNER', 'disabled'),
        'clamd' => [
            'host' => env('CLAMD_HOST', ''),
            'port' => (int) env('CLAMD_PORT', 3310),
            'timeout' => (int) env('CLAMD_TIMEOUT', 30),
        ],
    ],

    'csp' => [
        // Enforced since #488 after a clean report-only run (decision D6);
        // CSP_ENFORCE=false drops back to report-only. The nonce for inline
        // scripts and the Vite dev server are added per request. Tiny hosts
        // follow https://www.tiny.cloud/docs/tinymce/latest/tinymce-and-csp/.
        'enforce' => (bool) env('CSP_ENFORCE', true),
        'report' => (bool) env('CSP_REPORT', true),
        'directives' => [
            'default-src' => ["'self'"],
            // Google reCAPTCHA v3 (#637) loads only on the protected public forms.
            'script-src' => ["'self'", 'https://*.tiny.cloud', 'https://*.tinymce.com', 'https://www.google.com/recaptcha/', 'https://www.gstatic.com/recaptcha/'],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://*.tiny.cloud', 'https://*.tinymce.com'],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:', 'https://*.tiny.cloud', 'https://*.tinymce.com'],
            'connect-src' => ["'self'", 'blob:', 'https://*.tiny.cloud', 'https://*.tinymce.com', 'https://www.google.com/recaptcha/'],
            'frame-src' => ["'self'", 'https://bookings.cloud.microsoft', 'https://outlook.office365.com', 'https://www.google.com/recaptcha/', 'https://recaptcha.google.com/recaptcha/'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ],
    ],

    'bot_protection' => [
        // Honeypot, minimum fill time and Google reCAPTCHA v3 on the public
        // register, forgot password, ticket, recruitment apply and login forms
        // (#637). The reCAPTCHA keys and score thresholds are in Admin →
        // Settings → Integrations; without keys only the honeypot and timing apply.
        'enabled' => (bool) env('BOT_PROTECTION_ENABLED', true),
        'min_fill_seconds' => (int) env('BOT_PROTECTION_MIN_FILL_SECONDS', 2),
        'google_timeout_seconds' => 3,
        'login_failures_before_recaptcha' => 3,
        'login_failure_window_seconds' => 3600,
        // When Google cannot be reached, unverified submissions per form and IP address.
        'unverified_attempts' => 5,
        'unverified_window_seconds' => 600,
    ],

    'bookings' => [
        // Public Microsoft Bookings pages (#488), keyed by the /bookings/{page} slug.
        // The CSP frame-src above must allow these hosts.
        'pages' => [
            'apes' => 'https://bookings.cloud.microsoft/book/APESBookings@apes.org.uk/',
            'shelter-and-rescue' => 'https://bookings.cloud.microsoft/book/APESShelterRescueBookings@apes.org.uk/',
            'pet-care-clinic' => 'https://bookings.cloud.microsoft/book/APESPetCareClinicBookings@apes.org.uk/',
        ],
    ],

    'call_logs' => [
        // UK GDPR retention for staff call logs (#480); owner decision: 24 months.
        'retention_months' => (int) env('CALL_LOG_RETENTION_MONTHS', 24),
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
