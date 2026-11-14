<?php

namespace App\Core\Qa;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\LegacyAccessCompatibilityAdapter;
use App\Support\DefaultJobRoles;
use Database\Seeders\LocalQaSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fake accounts for previewing MyAPES Account locally as each account type
 * and each common staff stack. Local Herd only, behind QA_LOGIN_ENABLED.
 */
final class QaLogin
{
    /** Flashed for the request straight after a role switch. */
    public const SWITCHED = 'qa.switched';

    /** Flashed when a page needs a fresh sign-in, to ask the person to confirm their profile. */
    public const CONFIRM = 'qa.confirm';

    public static function enabled(): bool
    {
        return config('myapes.qa_login_enabled') === true && app()->environment('local');
    }

    /**
     * The profile key of a QA account, or null for any other account.
     */
    public static function profileFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach (self::profiles() as $key => $profile) {
            if ($user->email === $profile['email']) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A path on this site to return to, or null if it could lead elsewhere.
     */
    public static function safeReturnPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 2000) {
            return null;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\s]/', $path) === 1) {
            return null;
        }

        return $path;
    }

    /**
     * Fixed allowlist of QA profiles.
     *
     * @return array<string, array{label: string, email: string, access_level: string, job_roles: list<string>}>
     */
    public static function profiles(): array
    {
        return [
            'service_user' => [
                'label' => 'Service user',
                'email' => LocalQaSeeder::SERVICE_USER_EMAIL,
                'access_level' => User::ROLE_SERVICE_USER,
                'job_roles' => [],
            ],
            'student' => [
                'label' => 'Student',
                'email' => LocalQaSeeder::STUDENT_EMAIL,
                'access_level' => User::ROLE_STUDENT,
                'job_roles' => [],
            ],
            'volunteer' => [
                'label' => 'Volunteer',
                'email' => LocalQaSeeder::VOLUNTEER_EMAIL,
                'access_level' => User::ROLE_VOLUNTEER,
                'job_roles' => [],
            ],
            'staff' => [
                'label' => 'Staff',
                'email' => LocalQaSeeder::STAFF_EMAIL,
                'access_level' => User::ROLE_STAFF,
                'job_roles' => [],
            ],
            'admin' => [
                'label' => 'Administrator',
                'email' => LocalQaSeeder::ADMIN_EMAIL,
                'access_level' => User::ROLE_ADMIN,
                'job_roles' => [],
            ],
            'staff-hr' => [
                'label' => 'Staff + HR',
                'email' => LocalQaSeeder::HR_EMAIL,
                'access_level' => User::ROLE_STAFF,
                'job_roles' => [DefaultJobRoles::HUMAN_RESOURCES],
            ],
            'staff-vet' => [
                'label' => 'Staff + Vet',
                'email' => LocalQaSeeder::VET_EMAIL,
                'access_level' => User::ROLE_STAFF,
                'job_roles' => [DefaultJobRoles::VET],
            ],
            'staff-vet-nurse' => [
                'label' => 'Staff + Vet nurse',
                'email' => LocalQaSeeder::VET_NURSE_EMAIL,
                'access_level' => User::ROLE_STAFF,
                'job_roles' => [DefaultJobRoles::VET_NURSE],
            ],
            'staff-receptionist' => [
                'label' => 'Staff + Receptionist',
                'email' => LocalQaSeeder::RECEPTIONIST_EMAIL,
                'access_level' => User::ROLE_STAFF,
                'job_roles' => [DefaultJobRoles::RECEPTIONIST],
            ],
        ];
    }

    /**
     * Profiles for the picker, split into account types and common stacks.
     *
     * @return array{groups: list<array{key: string, label: string}>, stacks: list<array{key: string, label: string}>}
     */
    public static function picker(): array
    {
        $groups = [];
        $stacks = [];

        foreach (self::profiles() as $key => $profile) {
            $entry = [
                'key' => $key,
                'label' => $profile['label'],
            ];

            if ($profile['job_roles'] === []) {
                $groups[] = $entry;
            } else {
                $stacks[] = $entry;
            }
        }

        return ['groups' => $groups, 'stacks' => $stacks];
    }

    public static function label(string $profile): string
    {
        return self::profiles()[$profile]['label'] ?? $profile;
    }

    public static function emailFor(string $profile): string
    {
        return self::profiles()[$profile]['email']
            ?? throw new InvalidArgumentException("Unknown QA profile [{$profile}].");
    }

    /**
     * Sends the request straight after a role switch to the dashboard when the
     * new profile may not open the page it returned to.
     */
    public static function fallbackAfterSwitch(Request $request, int $status): ?RedirectResponse
    {
        if (! in_array($status, [403, 404], true) || ! $request->isMethod('GET') || ! $request->hasSession() || ! self::enabled()) {
            return null;
        }

        if ($request->session()->pull(self::SWITCHED) !== true) {
            return null;
        }

        return redirect()->route('dashboard')
            ->with('qa.notice', __('qa.notice.switched_fallback'));
    }

    public static function userFor(string $profile): User
    {
        $definition = self::profiles()[$profile]
            ?? throw new InvalidArgumentException("Unknown QA profile [{$profile}].");

        app(AuthorizationMetadataSynchronizer::class)->synchronize();

        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $definition['email']]);

        if (! $user->exists) {
            $user->forceFill([
                'name' => 'QA '.$definition['label'],
                'username' => str_replace('@myapes.local', '', $definition['email']),
                'password' => Str::password(40),
                'oidc_sub' => null,
                'identity_type' => User::IDENTITY_LOCAL,
                'ldap_groups' => [],
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);
        } else {
            $user->forceFill([
                'name' => 'QA '.$definition['label'],
                'oidc_sub' => null,
                'identity_type' => User::IDENTITY_LOCAL,
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);
        }

        app(LegacyAccessCompatibilityAdapter::class)
            ->write($user, $definition['access_level'])
            ->save();

        $authorization = app(AuthorizationProfile::class);
        $protectedRoleName = $authorization->protectedRoleForLegacy($definition['access_level']);
        $protectedRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $protectedRoleName)
            ->firstOrFail();

        $materializer = app(AuthorizationRoleMaterializer::class);
        $materializer->grant($user, $protectedRole, RoleSource::SOURCE_SYSTEM);

        $catalogue = array_keys(DefaultJobRoles::catalogue());
        foreach ($catalogue as $jobRoleName) {
            $jobRole = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $jobRoleName)
                ->first();

            if ($jobRole === null) {
                continue;
            }

            if (in_array($jobRoleName, $definition['job_roles'], true)) {
                $materializer->grant($user, $jobRole, RoleSource::SOURCE_LOCAL, actor: $user);
            } elseif ($user->roles->contains('id', $jobRole->id)) {
                $materializer->revoke($user, $jobRole, RoleSource::SOURCE_LOCAL);
            }
        }

        return $user->fresh(['roles']) ?? $user;
    }
}
