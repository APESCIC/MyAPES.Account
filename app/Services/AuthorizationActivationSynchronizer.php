<?php

namespace App\Services;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationLifecycleException;
use App\Exceptions\DirectoryIdentityNotFound;
use App\Exceptions\DirectoryUnavailable;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AuthorizationActivationSynchronizer
{
    public function __construct(
        private readonly LdapGroupResolver $directory,
        private readonly AuthorizationMetadataSynchronizer $metadata,
        private readonly AuthorizationAccountSynchronizer $accounts,
        private readonly DirectoryRoleSynchronizer $directoryRoles,
        private readonly AuthorizationProfile $profile,
        private readonly AuthorizationPhaseBSchemaInspector $schema,
        private readonly AuthorizationCompatibilityDatabaseGuard $compatibilityGuard,
    ) {}

    /**
     * @return array{users: int, directory_identities: int, sessions_rotated: int}
     */
    public function synchronize(): array
    {
        $this->schema->assertReady();
        $users = User::query()->orderBy('id')->get();
        $identityFingerprint = $this->identityFingerprint($users);
        $directoryGroups = [];
        $directoryIdentities = 0;

        foreach ($users as $user) {
            if (! $user->hasDirectoryIdentity()) {
                continue;
            }

            $directoryIdentities++;

            try {
                $directoryGroups[$user->getKey()] = $this->directory
                    ->resolveByEmail($user->email);
            } catch (DirectoryIdentityNotFound) {
                $directoryGroups[$user->getKey()] = [];
            } catch (DirectoryUnavailable) {
                throw new AuthorizationLifecycleException(
                    'directory_unavailable',
                );
            } catch (Throwable) {
                throw new AuthorizationLifecycleException(
                    'directory_unavailable',
                );
            }
        }

        try {
            return DB::transaction(function () use (
                $users,
                $identityFingerprint,
                $directoryGroups,
                $directoryIdentities,
            ): array {
                $state = AuthorizationState::query()
                    ->whereKey(AuthorizationState::SINGLETON_ID)
                    ->lockForUpdate()
                    ->first();

                if ($state === null) {
                    throw new AuthorizationLifecycleException(
                        'authorization_schema',
                    );
                }

                $lockedUsers = User::query()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (! hash_equals(
                    $identityFingerprint,
                    $this->identityFingerprint($lockedUsers),
                )) {
                    throw new AuthorizationLifecycleException(
                        'authorization_snapshot_changed',
                    );
                }

                $firstActivation = $state->session_cutover_completed_at === null;
                $this->metadata->synchronize();
                $this->compatibilityGuard->reconcileLegacySources();

                foreach ($lockedUsers as $user) {
                    if ($user->identity_type === User::IDENTITY_LOCAL) {
                        $this->accounts->grantPublicBaseline($user);
                    } else {
                        $this->directoryRoles->synchronize(
                            $user,
                            $directoryGroups[$user->getKey()] ?? [],
                            ! $firstActivation,
                        );
                    }
                }

                $administrators = User::query()
                    ->eligibleAdministrators()
                    ->count();

                if ($administrators < 1) {
                    throw new AuthorizationLifecycleException(
                        'administrator_unavailable',
                    );
                }

                $rotated = 0;

                if ($firstActivation) {
                    foreach ($lockedUsers as $user) {
                        $user->forceFill([
                            'authorization_epoch' => $user->authorization_epoch + 1,
                            'remember_token' => Str::random(60),
                        ])->save();
                        $rotated++;
                    }

                    $state->forceFill([
                        'authorization_epoch' => $state->authorization_epoch + 1,
                        'session_cutover_completed_at' => now(),
                    ])->save();
                }

                return [
                    'users' => $users->count(),
                    'directory_identities' => $directoryIdentities,
                    'sessions_rotated' => $rotated,
                ];
            });
        } catch (AuthorizationLifecycleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AuthorizationLifecycleException(
                'synchronization_failed',
            );
        }
    }

    /**
     * @param  iterable<int, User>  $users
     */
    private function identityFingerprint(iterable $users): string
    {
        $snapshot = [];

        foreach ($users as $user) {
            $snapshot[] = [
                'id' => (int) $user->getKey(),
                'identity_type' => (string) $user->identity_type,
                'oidc_sub' => is_string($user->oidc_sub)
                    ? $user->oidc_sub
                    : null,
                'email' => (string) $user->email,
            ];
        }

        return hash(
            'sha256',
            json_encode($snapshot, JSON_THROW_ON_ERROR),
        );
    }
}
