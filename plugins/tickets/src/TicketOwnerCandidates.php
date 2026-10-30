<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * People who can own a ticket in one service: public users who can view their own
 * tickets there, and eligible staff who can view all of them (#456).
 */
final class TicketOwnerCandidates
{
    public const MIN_TERM_LENGTH = 2;

    public const LIMIT = 10;

    /**
     * @return Collection<int, User>
     */
    public function search(string $permissionPrefix, string $term): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return collect();
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $matching = static fn (Builder $query): Builder => $query->where(
            static fn (Builder $match): Builder => $match
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('username', 'like', $like),
        );

        return $this->eligible($permissionPrefix, $matching)
            ->sortBy(static fn (User $user): string => mb_strtolower($user->name))
            ->take(self::LIMIT)
            ->values();
    }

    public function isEligible(string $permissionPrefix, int $userId): bool
    {
        return $this->eligible(
            $permissionPrefix,
            static fn (Builder $query): Builder => $query->whereKey($userId),
        )->isNotEmpty();
    }

    /**
     * @param  callable(Builder): Builder  $constrain
     * @return Collection<int, User>
     */
    private function eligible(string $permissionPrefix, callable $constrain): Collection
    {
        $publicOwners = $constrain(User::query()->withAuthorizationPermission($permissionPrefix.'view-own'))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
        $staffOwners = $constrain(User::query()->eligibleStaff()->withAuthorizationPermission($permissionPrefix.'view-all'))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return $publicOwners->merge($staffOwners)->unique('id');
    }
}
