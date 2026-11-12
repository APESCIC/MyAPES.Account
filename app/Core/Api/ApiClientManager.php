<?php

namespace App\Core\Api;

use App\Core\Accounts\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Issues, rotates and revokes API client tokens for Admin → Settings →
 * API clients (#639). The plain token is returned once and never stored,
 * logged or audited; only its hash is kept by Sanctum.
 */
final class ApiClientManager
{
    public const DEFAULT_EXPIRY_DAYS = 90;

    public const MAX_EXPIRY_DAYS = 365;

    public const ROTATION_OVERLAP_HOURS = 24;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, organisation: string, abilities: list<string>, ip_allowlist: list<string>|null, rate_limit_per_minute: int}  $attributes
     * @return array{ApiClient, string}
     */
    public function create(array $attributes, User $admin, int $expiryDays = self::DEFAULT_EXPIRY_DAYS): array
    {
        return DB::transaction(function () use ($attributes, $admin, $expiryDays): array {
            $client = ApiClient::query()->create([...$attributes, 'created_by' => $admin->id]);
            $plain = $this->issue($client, $expiryDays);

            $this->audit->record('admin.api_clients.created', $admin, $client, [
                'name' => $client->name,
                'organisation' => $client->organisation,
                'abilities' => $client->abilities,
                'expires_in_days' => $expiryDays,
                'ip_allowlist' => $client->ip_allowlist ?? [],
            ]);

            return [$client, $plain];
        });
    }

    /**
     * Issues a new token. Older tokens keep working for the overlap, so the
     * app can switch without downtime, then expire.
     */
    public function rotate(ApiClient $client, User $admin, int $expiryDays = self::DEFAULT_EXPIRY_DAYS): string
    {
        return DB::transaction(function () use ($client, $admin, $expiryDays): string {
            $overlapEnds = now()->addHours(self::ROTATION_OVERLAP_HOURS);
            $client->tokens()
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $overlapEnds))
                ->update(['expires_at' => $overlapEnds]);

            $plain = $this->issue($client, $expiryDays);
            $this->audit->record('admin.api_clients.rotated', $admin, $client, [
                'expires_in_days' => $expiryDays,
                'previous_tokens_expire_at' => $overlapEnds->toIso8601String(),
            ]);

            return $plain;
        });
    }

    public function revoke(ApiClient $client, User $admin): void
    {
        DB::transaction(function () use ($client, $admin): void {
            $client->forceFill(['revoked_at' => now()])->save();
            $client->tokens()->delete();
            $this->audit->record('admin.api_clients.revoked', $admin, $client, ['name' => $client->name]);
        });
    }

    /**
     * @param  array{ip_allowlist: list<string>|null, rate_limit_per_minute: int}  $attributes
     */
    public function update(ApiClient $client, array $attributes, User $admin): void
    {
        $client->fill($attributes);
        $changed = array_keys($client->getDirty());
        $client->save();

        if ($changed !== []) {
            $this->audit->record('admin.api_clients.updated', $admin, $client, [
                'changed' => $changed,
                'ip_allowlist' => $client->ip_allowlist ?? [],
                'rate_limit_per_minute' => $client->rate_limit_per_minute,
            ]);
        }
    }

    private function issue(ApiClient $client, int $expiryDays): string
    {
        $days = max(1, min(self::MAX_EXPIRY_DAYS, $expiryDays));

        return $client->createToken($client->name.' token', $client->abilities, now()->addDays($days))->plainTextToken;
    }
}
