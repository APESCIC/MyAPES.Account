<?php

namespace App\Core\Search\Providers;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use App\Services\AuthorizationProfile;

/**
 * Search people for the workspace palette (#527).
 */
final class UsersRecordSearchProvider implements RecordSearchProvider
{
    public function type(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return __('workspace.palette.records.users');
    }

    public function search(User $actor, string $q, ?string $status = null): array
    {
        $canAdmin = $actor->can('admin.users.view') || $actor->can('admin.users.manage');
        $canClients = $actor->can(AuthorizationProfile::PERMISSION_CLIENT_VIEW);

        if (! $canAdmin && ! $canClients) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $candidates = User::query()
            ->with(['profile', 'staffProfile'])
            ->where(static function ($query) use ($like): void {
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhereHas('profile', static function ($profile) use ($like): void {
                        $profile
                            ->where('phone', 'like', $like)
                            ->orWhere('mobile_number', 'like', $like)
                            ->orWhere('landline_number', 'like', $like);
                    })
                    ->orWhereHas('staffProfile', static function ($profile) use ($like): void {
                        $profile->where('work_phone', 'like', $like);
                    });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        $hits = [];

        foreach ($candidates as $user) {
            $profile = $user->profile;
            $staffProfile = $user->staffProfile;
            $rank = RecordRanker::bestScore(
                $q,
                reference: (string) $user->id,
                partialFields: [
                    $user->name,
                    $user->email,
                    $user->username,
                    $profile?->phone,
                    $profile?->mobile_number,
                    $profile?->landline_number,
                    $staffProfile?->work_phone,
                ],
                recordStatus: $user->suspended_at === null ? 'active' : 'suspended',
                statusFilter: $status,
            );

            if ($rank === null) {
                continue;
            }

            $url = $canAdmin
                ? route(
                    app(AccountClassifier::class)->isStaffSide($user)
                        ? 'admin.staff-accounts.show'
                        : 'admin.public-accounts.show',
                    $user,
                )
                : route('clients.show', $user);

            $hits[] = new RecordHit(
                type: $this->type(),
                id: (string) $user->id,
                label: (string) $user->name,
                url: $url,
                rank: $rank,
                subtitle: (string) $user->email,
                status: $user->suspended_at === null ? 'active' : 'suspended',
                reference: '#'.$user->id,
            );
        }

        return $hits;
    }
}
