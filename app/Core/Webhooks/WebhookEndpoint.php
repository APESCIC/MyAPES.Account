<?php

namespace App\Core\Webhooks;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An HTTPS address that receives signed events for one organisation (#641).
 * The signing secret is encrypted at rest and shown to admins only once.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $organisation
 * @property list<string> $events
 * @property string $secret
 * @property bool $active
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 */
class WebhookEndpoint extends Model
{
    protected $fillable = ['name', 'url', 'organisation', 'events', 'secret', 'active', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<WebhookEndpoint>  $query
     */
    public function scopeListeningFor(Builder $query, string $event, string $organisation): void
    {
        $query->where('active', true)
            ->where('organisation', $organisation)
            ->whereJsonContains('events', $event);
    }
}
