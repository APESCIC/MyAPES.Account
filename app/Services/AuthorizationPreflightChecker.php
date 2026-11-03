<?php

namespace App\Services;

use App\Exceptions\AuthorizationLifecycleException;
use App\Exceptions\AuthReadinessException;
use App\Exceptions\DirectoryIdentityNotFound;
use App\Exceptions\DirectoryUnavailable;
use App\Support\AccessCompatibilityDatabaseGuard;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthorizationPreflightChecker
{
    public function __construct(
        private readonly AccessCompatibilityDatabaseGuard $phaseAGuard,
        private readonly AuthorizationCompatibilityDatabaseGuard $phaseBGuard,
        private readonly AuthReadinessChecker $authReadiness,
        private readonly AuthorizationPhaseBSchemaInspector $phaseBSchema,
        private readonly LdapGroupResolver $directory,
    ) {}

    /**
     * @return array{
     *     phase: string,
     *     users: int,
     *     groups: int,
     *     administrators: int
     * }
     */
    public function check(): array
    {
        if (! in_array(
            DB::connection()->getDriverName(),
            ['sqlite', 'mysql'],
            true,
        )) {
            throw new AuthorizationLifecycleException('database_driver');
        }

        $users = DB::table('users')->count();
        $phase = Schema::hasColumn('users', 'role')
            ? $this->checkPhaseA()
            : $this->checkPhaseB();

        try {
            $groups = $this->authReadiness->check();
        } catch (AuthReadinessException $exception) {
            $check = str_starts_with($exception->check, 'oidc_')
                ? 'oidc_readiness'
                : 'directory_readiness';

            throw new AuthorizationLifecycleException(
                $check,
                "{$exception->check}:{$exception->reason}",
            );
        }

        $administrators = $this->eligibleOidcAdministrators();

        return [
            'phase' => $phase,
            'users' => $users,
            'groups' => $groups,
            'administrators' => $administrators,
        ];
    }

    private function checkPhaseA(): string
    {
        if (! Schema::hasColumns('users', [
            'identity_type',
            'legacy_access_level',
            'role',
        ])
            || ! $this->phaseAGuard->isInstalled()
            || $this->phaseBGuard->isInstalled()
            || DB::table('users')
                ->where(static function ($query): void {
                    $query
                        ->whereNull('role')
                        ->orWhereNull('legacy_access_level')
                        ->orWhereColumn('role', '<>', 'legacy_access_level');
                })
                ->exists()) {
            throw new AuthorizationLifecycleException('authorization_schema');
        }

        return 'phase_a';
    }

    private function checkPhaseB(): string
    {
        $this->phaseBSchema->assertReady();

        return 'phase_b';
    }

    private function eligibleOidcAdministrators(): int
    {
        $candidates = DB::table('users')
            ->select(['id', 'email'])
            ->whereNotNull('oidc_sub')
            ->whereRaw("TRIM(oidc_sub) <> ''");

        if (Schema::hasColumn('users', 'suspended_at')) {
            $candidates->whereNull('suspended_at');
        }

        $eligible = 0;

        foreach ($candidates->orderBy('id')->get() as $candidate) {
            if (! is_string($candidate->email)
                || trim($candidate->email) === '') {
                continue;
            }

            try {
                $groups = $this->directory->resolveByEmail(
                    trim($candidate->email),
                );
            } catch (DirectoryIdentityNotFound) {
                continue;
            } catch (DirectoryUnavailable) {
                throw new AuthorizationLifecycleException(
                    'directory_readiness',
                    'ldap_directory:unavailable',
                );
            }

            if (in_array('myapesaccount.admin', $groups, true)) {
                $eligible += 1;
            }
        }

        if ($eligible < 1) {
            throw new AuthorizationLifecycleException(
                'administrator_unavailable',
            );
        }

        return $eligible;
    }
}
