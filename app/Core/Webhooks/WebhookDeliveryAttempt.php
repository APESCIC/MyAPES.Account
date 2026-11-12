<?php

namespace App\Core\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try at sending a webhook delivery (#642): the HTTP status, how long it
 * took and, on failure, a short reason. Response bodies are never kept.
 */
class WebhookDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['webhook_delivery_id', 'attempt', 'status_code', 'duration_ms', 'error'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'status_code' => 'integer', 'duration_ms' => 'integer'];
    }

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
