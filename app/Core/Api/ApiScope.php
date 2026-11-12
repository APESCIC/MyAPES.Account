<?php

namespace App\Core\Api;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Who an API client may see (#640). Results are always limited to the
 * client's organisation: people who chose that service, and staff in that
 * team. APES CIC is the umbrella organisation, so its clients see all staff.
 * Suspended accounts are never returned.
 */
final class ApiScope
{
    public const UMBRELLA_ORGANISATION = 'apes-cic';

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly AccountClassifier $classifier) {}

    public static function client(Request $request): ApiClient
    {
        $client = $request->user();
        abort_unless($client instanceof ApiClient, 401);

        return $client;
    }

    public static function perPage(Request $request): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE)));
    }

    /**
     * @return Builder<User>
     */
    public function people(ApiClient $client): Builder
    {
        return $this->classifier->scopePublic(User::query())
            ->whereNull('suspended_at')
            ->whereHas('serviceSelections', static fn (Builder $query) => $query->where('sub_core_key', $client->organisation));
    }

    /**
     * @return Builder<User>
     */
    public function staff(ApiClient $client): Builder
    {
        $query = $this->classifier->scopeStaffSide(User::query())
            ->whereNull('suspended_at')
            ->with('staffProfile');

        if ($client->organisation !== self::UMBRELLA_ORGANISATION) {
            $query->whereHas('staffProfile', static fn (Builder $profile) => $profile->where('team', $client->organisation));
        }

        return $query;
    }
}
