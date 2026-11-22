<?php

namespace App\Core\Mail;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Modules\SubCoreNavigation;
use App\Services\RegistryModuleNavigationProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves User recipients from a staff message filter (#503).
 */
final class StaffMessageRecipientResolver
{
    public function __construct(
        private readonly AccountClassifier $accounts,
        private readonly ModuleRegistry $registry,
        private readonly RegistryModuleNavigationProvider $navigation,
    ) {}

    /**
     * @param  array<string, mixed>  $filter
     */
    public function resolve(
        User $sender,
        array $filter,
        string $category,
        bool $canSendAll,
    ): StaffMessageRecipientResolution {
        $mode = (string) ($filter['mode'] ?? '');

        if ($mode === 'all' && ! $canSendAll) {
            throw new AuthorizationException;
        }

        if ($mode === 'module' && ! $canSendAll) {
            $module = (string) ($filter['module'] ?? '');
            if (! in_array($module, $this->allowedModuleKeys($sender), true)) {
                throw new AuthorizationException;
            }
        }

        if ($mode === 'group' && ! $canSendAll) {
            $group = (string) ($filter['group'] ?? '');
            if (in_array($group, [AccountClassifier::STAFF, AccountClassifier::ADMIN], true)) {
                throw new AuthorizationException;
            }
        }

        $users = match ($mode) {
            'all' => $this->queryEligible()->get(),
            'group' => $this->resolveGroup((string) ($filter['group'] ?? '')),
            'module' => $this->resolveModule((string) ($filter['module'] ?? '')),
            'users' => $this->resolveUsers($filter['user_ids'] ?? []),
            default => collect(),
        };

        if (! $canSendAll && $mode === 'users') {
            $users = $this->restrictNamedRecipients($sender, $users);
        }

        $users = $this->deduplicateWithEmail($users);

        if ($category === StaffMessage::CATEGORY_OPTIONAL) {
            $users->loadMissing('contactPreference');
            $optedOut = $users->filter(
                static fn (User $user): bool => ! (bool) $user->contactPreference?->email,
            );
            $recipients = $users->reject(
                static fn (User $user): bool => ! (bool) $user->contactPreference?->email,
            )->values();

            return new StaffMessageRecipientResolution($recipients, $optedOut->count());
        }

        return new StaffMessageRecipientResolution($users->values(), 0);
    }

    /**
     * @return list<string>
     */
    public function allowedModuleKeys(User $sender): array
    {
        if ($sender->can('staff.messages.send.all')) {
            return collect($this->registry->permissions())
                ->map(static fn ($descriptor): string => $descriptor->subCoreKey.':'.$descriptor->moduleKey)
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        return collect($this->navigation->forUser($sender))
            ->flatMap(static fn (SubCoreNavigation $subCore): Collection => collect($subCore->modules))
            ->map(static fn ($item): string => $item->instanceKey)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function userIdsWithinSenderScope(User $sender, array $userIds, bool $canSendAll): array
    {
        $normalized = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $userIds)));

        if ($canSendAll || $normalized === []) {
            return $normalized;
        }

        $users = $this->resolveUsers($normalized);
        $scoped = $this->restrictNamedRecipients($sender, $users);

        return $scoped->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param  list<int|string>  $userIds
     * @return Collection<int, User>
     */
    private function resolveUsers(array $userIds): Collection
    {
        $ids = collect($userIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return $this->queryEligible()->whereIn('id', $ids)->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveGroup(string $group): Collection
    {
        if (! in_array($group, [
            AccountClassifier::PUBLIC,
            AccountClassifier::STUDENT,
            AccountClassifier::VOLUNTEER,
            AccountClassifier::STAFF,
            AccountClassifier::ADMIN,
        ], true)) {
            return collect();
        }

        $query = $this->queryEligible();
        $this->accounts->scopeType($query, $group);

        return $query->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveModule(string $moduleKey): Collection
    {
        $permissionNames = $this->permissionsForModule($moduleKey);

        if ($permissionNames === []) {
            return collect();
        }

        return $this->queryEligible()
            ->where(static function (Builder $query) use ($permissionNames): void {
                $query->whereHas(
                    'roles.permissions',
                    static fn (Builder $permissionQuery): Builder => $permissionQuery
                        ->where('permissions.guard_name', 'web')
                        ->whereIn('permissions.name', $permissionNames),
                )->orWhereHas(
                    'permissions',
                    static fn (Builder $permissionQuery): Builder => $permissionQuery
                        ->where('permissions.guard_name', 'web')
                        ->whereIn('permissions.name', $permissionNames),
                );
            })
            ->get();
    }

    /**
     * Without send-all, named recipients cannot include staff-side accounts, and
     * senders who only have module navigation may only target users in those modules.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function restrictNamedRecipients(User $sender, Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $users = $users
            ->reject(fn (User $user): bool => $this->accounts->isStaffSide($user))
            ->values();

        $allowedKeys = $this->allowedModuleKeys($sender);

        if ($allowedKeys === [] || $this->accounts->isStaffSide($sender)) {
            return $users;
        }

        $permissionNames = collect($allowedKeys)
            ->flatMap(fn (string $key): array => $this->permissionsForModule($key))
            ->unique()
            ->values()
            ->all();

        if ($permissionNames === []) {
            return collect();
        }

        $scopedIds = $this->queryEligible()
            ->whereIn('id', $users->pluck('id')->all())
            ->where(static function (Builder $query) use ($permissionNames): void {
                $query->whereHas(
                    'roles.permissions',
                    static fn (Builder $permissionQuery): Builder => $permissionQuery
                        ->where('permissions.guard_name', 'web')
                        ->whereIn('permissions.name', $permissionNames),
                )->orWhereHas(
                    'permissions',
                    static fn (Builder $permissionQuery): Builder => $permissionQuery
                        ->where('permissions.guard_name', 'web')
                        ->whereIn('permissions.name', $permissionNames),
                );
            })
            ->pluck('id')
            ->all();

        return $users->whereIn('id', $scopedIds)->values();
    }

    /**
     * @return list<string>
     */
    private function permissionsForModule(string $moduleSlug): array
    {
        return collect($this->registry->permissions())
            ->filter(function ($descriptor) use ($moduleSlug): bool {
                $instanceKey = $descriptor->subCoreKey.':'.$descriptor->moduleKey;

                return $instanceKey === $moduleSlug || $descriptor->moduleKey === $moduleSlug;
            })
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Builder<User>
     */
    private function queryEligible(): Builder
    {
        return User::query()
            ->whereNull('suspended_at')
            ->whereNotNull('email')
            ->where('email', '!=', '');
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function deduplicateWithEmail(Collection $users): Collection
    {
        return $users
            ->unique(static fn (User $user): int => $user->id)
            ->filter(static fn (User $user): bool => is_string($user->email) && trim($user->email) !== '')
            ->values();
    }
}
