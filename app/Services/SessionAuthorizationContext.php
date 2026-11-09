<?php

namespace App\Services;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\DirectorySyncRun;
use App\Core\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SessionAuthorizationContext
{
    public const AUTHENTICATION_METHOD_KEY = 'myapes.authentication_method';

    public const AUTHORIZATION_EPOCH_KEY = 'myapes.authorization_epoch';

    public const DIRECTORY_VALIDATED_AT_KEY = 'myapes.directory_validated_at';

    public const DIRECTORY_GENERATION_KEY = 'myapes.directory_generation';

    public const METHOD_PASSWORD = 'password';

    public const METHOD_CLOUDRON_OIDC = 'cloudron_oidc';

    public const METHOD_QA = 'qa';

    public function recordPassword(Request $request, User $user): void
    {
        $this->record($request, $user, self::METHOD_PASSWORD);
    }

    public function recordCloudronOidc(
        Request $request,
        User $user,
        ?int $validatedAt = null,
    ): void {
        $this->record(
            $request,
            $user,
            self::METHOD_CLOUDRON_OIDC,
            $validatedAt ?? now()->timestamp,
        );
    }

    public function recordQa(Request $request, User $user): void
    {
        $this->record($request, $user, self::METHOD_QA);
    }

    public function refreshDirectoryValidation(Request $request, User $user): void
    {
        $request->session()->put([
            self::AUTHORIZATION_EPOCH_KEY => $this->authorizationEpoch($user),
            self::DIRECTORY_VALIDATED_AT_KEY => now()->timestamp,
            self::DIRECTORY_GENERATION_KEY => $this->directoryGeneration(),
        ]);
    }

    public function authenticationMethod(Request $request): ?string
    {
        // Queue workers and console commands (e.g. replies by email, #580) have no session.
        if (! $request->hasSession()) {
            return null;
        }

        $method = $request->session()->get(self::AUTHENTICATION_METHOD_KEY);

        return is_string($method) ? $method : null;
    }

    public function isCurrent(Request $request, User $user): bool
    {
        if ($user->suspended_at !== null) {
            return false;
        }

        $method = $this->authenticationMethod($request);

        if (! in_array($method, [
            self::METHOD_PASSWORD,
            self::METHOD_CLOUDRON_OIDC,
            self::METHOD_QA,
        ], true)) {
            return false;
        }

        if ($method === self::METHOD_QA
            && ! app()->environment(['local', 'testing'])) {
            return false;
        }

        if ($method === self::METHOD_CLOUDRON_OIDC
            && ! $user->hasDirectoryIdentity()) {
            return false;
        }

        return (int) $request->session()->get(
            self::AUTHORIZATION_EPOCH_KEY,
            0,
        ) === $this->authorizationEpoch($user);
    }

    public function permitsDirectoryRestricted(
        Request $request,
        User $user,
    ): bool {
        if (! $this->isCurrent($request, $user)) {
            return false;
        }

        $method = $this->authenticationMethod($request);

        if ($method === self::METHOD_QA) {
            return app()->environment(['local', 'testing']);
        }

        if ($method !== self::METHOD_CLOUDRON_OIDC
            || ! $user->hasDirectoryIdentity()) {
            return false;
        }

        $validatedAt = (int) $request->session()->get(
            self::DIRECTORY_VALIDATED_AT_KEY,
            0,
        );
        $age = now()->timestamp - $validatedAt;
        $interval = max(
            1,
            (int) config('myapes.directory.revalidate_seconds', 300),
        );
        $generation = (int) $request->session()->get(
            self::DIRECTORY_GENERATION_KEY,
            -1,
        );

        return $validatedAt > 0
            && $age >= 0
            && $age < $interval
            && $generation === $this->directoryGeneration();
    }

    /**
     * @return array<string, int|string>
     */
    public function valuesFor(
        User $user,
        string $method = self::METHOD_QA,
        ?int $validatedAt = null,
    ): array {
        $values = [
            self::AUTHENTICATION_METHOD_KEY => $method,
            self::AUTHORIZATION_EPOCH_KEY => $this->authorizationEpoch($user),
        ];

        if ($validatedAt !== null) {
            $values[self::DIRECTORY_VALIDATED_AT_KEY] = $validatedAt;
        }

        if ($method === self::METHOD_CLOUDRON_OIDC) {
            $values[self::DIRECTORY_GENERATION_KEY]
                = $this->directoryGeneration();
        }

        return $values;
    }

    private function record(
        Request $request,
        User $user,
        string $method,
        ?int $validatedAt = null,
    ): void {
        $request->session()->forget([
            self::AUTHENTICATION_METHOD_KEY,
            self::AUTHORIZATION_EPOCH_KEY,
            self::DIRECTORY_VALIDATED_AT_KEY,
            self::DIRECTORY_GENERATION_KEY,
        ]);
        $request->session()->put($this->valuesFor(
            $user,
            $method,
            $validatedAt,
        ));
    }

    private function authorizationEpoch(User $user): int
    {
        if (! is_numeric($user->getAttribute('authorization_epoch'))) {
            $user->refresh();
        }

        return (int) $user->getAttribute('authorization_epoch');
    }

    private function directoryGeneration(): int
    {
        if (! Schema::hasTable('directory_sync_runs')) {
            return 0;
        }

        $terminalGeneration = (int) (
            DirectorySyncRun::query()
                ->whereIn('status', [
                    DirectorySyncRun::STATUS_SUCCEEDED,
                    DirectorySyncRun::STATUS_FAILED,
                ])
                ->max('id') ?? 0
        );
        if (! Schema::hasTable('authorization_states')) {
            return $terminalGeneration;
        }

        $state = AuthorizationState::query()
            ->whereKey(AuthorizationState::SINGLETON_ID)
            ->first();
        $leaseIsActive = is_string($state?->directory_sync_owner_token)
            && $state->directory_sync_owner_token !== ''
            && $state->directory_sync_expires_at !== null
            && $state->directory_sync_expires_at->isFuture();

        if (! $leaseIsActive) {
            return $terminalGeneration;
        }

        $latestRun = (int) (DirectorySyncRun::query()->max('id') ?? 0);

        return max($terminalGeneration + 1, $latestRun);
    }
}
