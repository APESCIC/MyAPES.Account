<?php

namespace App\Core\Search\Providers;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearchProvider;
use App\Services\AuthorizationProfile;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Search Public Accounts and Public Profiles for the workspace palette and staff Find (#926).
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
        $canSearchClients = $actor->can(AuthorizationProfile::PERMISSION_CLIENT_SEARCH);

        if (! $canAdmin && ! $canSearchClients) {
            return [];
        }

        $like = '%'.$this->escapeLike($q).'%';
        $postcodeNeedle = $this->normalisePostcode($q);
        $dob = $this->parseDateOfBirth($q);

        $candidates = User::query()
            ->with(['profile', 'staffProfile', 'roles'])
            ->where(function (Builder $query) use ($like, $postcodeNeedle, $dob, $q): void {
                $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like);

                if (ctype_digit($q)) {
                    $query->orWhere('id', (int) $q);
                }

                $query->orWhereHas('profile', function (Builder $profile) use ($like, $postcodeNeedle, $dob): void {
                    $profile
                        ->where('phone', 'like', $like)
                        ->orWhere('mobile_number', 'like', $like)
                        ->orWhere('landline_number', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('preferred_name', 'like', $like)
                        ->orWhere('postcode', 'like', $like);

                    if ($postcodeNeedle !== null) {
                        $profile->orWhereRaw(
                            "REPLACE(UPPER(postcode), ' ', '') like ?",
                            [$postcodeNeedle.'%'],
                        );
                    }

                    if ($dob !== null) {
                        $profile->orWhereDate('date_of_birth', $dob->toDateString());
                    }
                })->orWhereHas('staffProfile', static function (Builder $profile) use ($like): void {
                    $profile->where('work_phone', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        if (! $canAdmin) {
            $classifier = app(AccountClassifier::class);
            $candidates = $candidates
                ->reject(static fn (User $user): bool => $classifier->isStaffSide($user))
                ->values();
        }

        $hits = [];

        foreach ($candidates as $user) {
            $profile = $user->profile;
            $staffProfile = $user->staffProfile;
            $dobValue = $profile?->date_of_birth;
            $dobString = $dobValue instanceof Carbon
                ? $dobValue->format('Y-m-d')
                : (is_string($dobValue) ? $dobValue : null);

            $postcode = is_string($profile?->postcode) ? $profile->postcode : null;
            $postcodeCompact = is_string($postcode)
                ? strtoupper((string) preg_replace('/\s+/', '', $postcode))
                : null;
            $dobUk = $dobValue instanceof Carbon ? $dobValue->format('d/m/Y') : null;

            $rank = RecordRanker::bestScore(
                $q,
                reference: (string) $user->id,
                partialFields: [
                    $user->name,
                    $user->email,
                    $user->username,
                    $profile?->first_name,
                    $profile?->last_name,
                    $profile?->preferred_name,
                    $profile?->phone,
                    $profile?->mobile_number,
                    $profile?->landline_number,
                    $postcode,
                    $postcodeCompact,
                    $dobString,
                    $dobUk,
                    $staffProfile?->work_phone,
                ],
                recordStatus: $user->suspended_at === null ? 'active' : 'suspended',
                statusFilter: $status,
            );

            // SQL already matched this candidate (postcode/DOB normalised forms); keep it.
            if ($rank === null) {
                $rank = RecordRanker::CONTAINS;
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
                subtitle: (string) ($user->email ?: ($profile?->postcode ?? '')),
                status: $user->accountState(),
                reference: '#'.$user->id,
            );
        }

        return $hits;
    }

    /**
     * Hydrate users for a list of search hit ids, preserving hit order (#933).
     *
     * @param  list<array<string, mixed>>  $hits
     * @return Collection<int, User>
     */
    public static function usersForHits(array $hits): Collection
    {
        $ids = collect($hits)
            ->pluck('id')
            ->filter(static fn ($id): bool => $id !== null && $id !== '')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->with(['roles', 'profile'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        return $ids
            ->map(static fn (int $id): ?User => $users->get($id))
            ->filter(static fn (?User $user): bool => $user instanceof User)
            ->values();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    private function normalisePostcode(string $q): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', trim($q)));
        if ($compact === '' || ! preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d?[A-Z]{0,2}$/i', $compact)) {
            return strlen($compact) >= 2 && preg_match('/[A-Z]/i', $compact) === 1
                ? $compact
                : null;
        }

        return $compact;
    }

    private function parseDateOfBirth(string $q): ?Carbon
    {
        $trimmed = trim($q);
        if ($trimmed === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $trimmed);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed instanceof Carbon && $parsed->format($format) === $trimmed) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }
}
