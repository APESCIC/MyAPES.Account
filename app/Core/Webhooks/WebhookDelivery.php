<?php

namespace App\Core\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One event sent to one endpoint (#642), with its status and every attempt.
 * A replay is a new delivery with the same event id and payload.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 */
class WebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const RETRYING = 'retrying';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['webhook_endpoint_id', 'event_id', 'event', 'payload', 'status', 'replay_of_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return HasMany<WebhookDeliveryAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt');
    }

    public function body(): string
    {
        return (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
