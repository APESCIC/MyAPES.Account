<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Support\PrivacyNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AccountLifecycleReadinessChecker
{
    /** @return array{users: int} */
    public function preflight(): array
    {
        $this->assertLegalConfiguration();

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'email')) {
            throw new RuntimeException('account_schema');
        }

        $collision = DB::table('users')
            ->selectRaw('LOWER(email) AS normalized_email')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($collision) {
            throw new RuntimeException('email_collision');
        }

        return ['users' => DB::table('users')->count()];
    }

    /** @return array{users: int} */
    public function check(): array
    {
        $result = $this->preflight();
        $requiredTables = [
            'user_service_selections',
            'user_contact_preferences',
            'contact_consent_events',
            'oidc_link_intents',
        ];

        if (! Schema::hasColumns('users', ['username', 'onboarding_completed_at'])
            || ! Schema::hasColumns('user_profiles', [
                'address_line_1',
                'postcode',
                'country',
                'mobile_number',
                'whatsapp_number',
                'telegram_username',
            ])
            || collect($requiredTables)->contains(
                static fn (string $table): bool => ! Schema::hasTable($table),
            )) {
            throw new RuntimeException('account_lifecycle_schema');
        }

        $staffRoles = [
            AuthorizationProfile::ROLE_STAFF,
            AuthorizationProfile::ROLE_ADMINISTRATOR,
        ];
        $publicAccounts = User::query()
            ->where('identity_type', User::IDENTITY_LOCAL)
            ->whereDoesntHave(
                'roles',
                static fn ($query) => $query
                    ->where('roles.guard_name', 'web')
                    ->whereIn('roles.name', $staffRoles),
            );

        if ((clone $publicAccounts)->whereDoesntHave('contactPreference')->exists()) {
            throw new RuntimeException('contact_preference_backfill');
        }

        if ((clone $publicAccounts)->whereDoesntHave('serviceSelections')->exists()) {
            throw new RuntimeException('service_selection_backfill');
        }

        return $result;
    }

    private function assertLegalConfiguration(): void
    {
        $policyVersion = config('myapes.consent.policy_version');
        if (! is_string($policyVersion) || trim($policyVersion) === '') {
            throw new RuntimeException('contact_consent_policy_version');
        }

        $configured = config('myapes.consent.privacy_notice_url');
        if (is_string($configured) && trim($configured) !== '') {
            if (filter_var($configured, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('privacy_notice_url');
            }

            return;
        }

        if (filter_var(PrivacyNotice::url(), FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('privacy_notice_url');
        }
    }
}
