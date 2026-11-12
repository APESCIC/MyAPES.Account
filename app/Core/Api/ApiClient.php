<?php

namespace App\Core\Api;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * One APES app allowed to call /api/v1 (#638, #639). Its tokens are Sanctum
 * personal access tokens; the client limits them to one organisation, its
 * abilities, a rate limit and, when set, an IP allowlist.
 *
 * @property int $id
 * @property string $name
 * @property string $organisation
 * @property list<string> $abilities
 * @property list<string>|null $ip_allowlist
 * @property int $rate_limit_per_minute
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 */
class ApiClient extends Model
{
    use HasApiTokens;

    private const LAST_USED_EVERY_SECONDS = 60;

    protected $fillable = [
        'name',
        'organisation',
        'abilities',
        'ip_allowlist',
        'rate_limit_per_minute',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'ip_allowlist' => 'array',
            'rate_limit_per_minute' => 'integer',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<ApiClient>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function allowsIp(?string $ip): bool
    {
        $allowlist = array_values(array_filter((array) $this->ip_allowlist));

        return $allowlist === [] || ($ip !== null && IpUtils::checkIp($ip, $allowlist));
    }

    public function recordUse(?string $ip): void
    {
        if ($this->last_used_at !== null
            && $this->last_used_ip === $ip
            && $this->last_used_at->gt(now()->subSeconds(self::LAST_USED_EVERY_SECONDS))) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
    }
}
