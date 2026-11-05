<?php

namespace App\Core\Accounts;

use App\Services\AuthorizationProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for whether an account is public or staff-side,
 * and which staff-side role it holds (#492).
 *
 * A directory-linked account (Cloudron OIDC or hybrid) with no protected
 * staff-side role still counts as staff, flagged as unmapped, so it never
 * lands in Public users. This classifies accounts for lists, navigation and
 * counts only: route gates keep using AuthorizationProfile.
 */
final class AccountClassifier
{
    public const PUBLIC = 'public';

    public const STUDENT = 'student';

    public const VOLUNTEER = 'volunteer';

    public const STAFF = 'staff';

    public const ADMIN = 'admin';

    /** Staff-side types, highest precedence first. */
    public const STAFF_SIDE_TYPES = [self::ADMIN, self::STAFF, self::VOLUNTEER, self::STUDENT];

    private const DIRECTORY_IDENTITIES = [User::IDENTITY_CLOUDRON_OIDC, User::IDENTITY_HYBRID];

    private const ROLE_FOR_TYPE = [
        self::ADMIN => AuthorizationProfile::ROLE_ADMINISTRATOR,
        self::STAFF => AuthorizationProfile::ROLE_STAFF,
        self::VOLUNTEER => AuthorizationProfile::ROLE_VOLUNTEER,
        self::STUDENT => AuthorizationProfile::ROLE_STUDENT,
    ];

    public function classify(User $user): string
    {
        $role = $this->topStaffSideRole($user);

        if ($role !== null) {
            return array_search($role, self::ROLE_FOR_TYPE, true);
        }

        return $this->isDirectoryLinked($user) ? self::STAFF : self::PUBLIC;
    }

    public function isStaffSide(User $user): bool
    {
        return $this->classify($user) !== self::PUBLIC;
    }

    /**
     * True when the account holds a protected staff-side role, which is what
     * staff-only routes such as Callers and the Staff guide require.
     */
    public function hasStaffSideRole(User $user): bool
    {
        return $this->topStaffSideRole($user) !== null;
    }

    /**
     * A directory-linked account with no protected staff-side role: staff,
     * but no Cloudron group maps it to a role yet.
     */
    public function isUnmapped(User $user): bool
    {
        return $this->isDirectoryLinked($user) && $this->topStaffSideRole($user) === null;
    }

    public function isDirectoryLinked(User $user): bool
    {
        return in_array($user->identity_type, self::DIRECTORY_IDENTITIES, true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where(static fn (Builder $query) => $query
                ->whereNull('identity_type')
                ->orWhereNotIn('identity_type', self::DIRECTORY_IDENTITIES))
            ->whereDoesntHave('roles', $this->rolesNamed(array_values(self::ROLE_FOR_TYPE)));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeStaffSide(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereIn('identity_type', self::DIRECTORY_IDENTITIES)
            ->orWhereHas('roles', $this->rolesNamed(array_values(self::ROLE_FOR_TYPE))));
    }

    /**
     * Accounts whose classification is $type, matching classify().
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        if ($type === self::PUBLIC) {
            return $this->scopePublic($query);
        }

        if (! isset(self::ROLE_FOR_TYPE[$type])) {
            throw new \InvalidArgumentException("Unknown account type [{$type}].");
        }

        $higher = array_slice(self::STAFF_SIDE_TYPES, 0, array_search($type, self::STAFF_SIDE_TYPES, true));
        $higherRoles = array_map(static fn (string $higherType): string => self::ROLE_FOR_TYPE[$higherType], $higher);

        return $query->where(function (Builder $query) use ($type, $higherRoles): void {
            $query->where(function (Builder $query) use ($type, $higherRoles): void {
                $query->whereHas('roles', $this->rolesNamed([self::ROLE_FOR_TYPE[$type]]));
                if ($higherRoles !== []) {
                    $query->whereDoesntHave('roles', $this->rolesNamed($higherRoles));
                }
            });

            if ($type === self::STAFF) {
                $query->orWhere(fn (Builder $query) => $query
                    ->whereIn('identity_type', self::DIRECTORY_IDENTITIES)
                    ->whereDoesntHave('roles', $this->rolesNamed(array_values(self::ROLE_FOR_TYPE))));
            }
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeUnmapped(Builder $query): Builder
    {
        return $query
            ->whereIn('identity_type', self::DIRECTORY_IDENTITIES)
            ->whereDoesntHave('roles', $this->rolesNamed(array_values(self::ROLE_FOR_TYPE)));
    }

    private function topStaffSideRole(User $user): ?string
    {
        $names = $user->relationLoaded('roles')
            ? $user->roles->where('guard_name', 'web')->pluck('name')->all()
            : $user->roles()->where('guard_name', 'web')->pluck('name')->all();

        foreach (self::ROLE_FOR_TYPE as $role) {
            if (in_array($role, $names, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     */
    private function rolesNamed(array $names): \Closure
    {
        return static fn (Builder $query) => $query
            ->where('roles.guard_name', 'web')
            ->whereIn('roles.name', $names);
    }
}
