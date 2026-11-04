<?php

namespace Plugins\Tickets\Models;

use App\Core\Accounts\User;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['support_ticket_id', 'user_id', 'message', 'is_staff_note'])]
class SupportTicketMessage extends Model
{
    protected function casts(): array
    {
        return [
            'message' => SanitisedHtml::class,
            'is_staff_note' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
