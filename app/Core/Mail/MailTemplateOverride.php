<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-edited subject and intro wording for one template and locale (#361).
 * Subject and intro are plain text; body is sanitised rich text (#486).
 */
#[Fillable(['template_key', 'locale', 'subject', 'intro', 'body', 'lock_version', 'updated_by'])]
class MailTemplateOverride extends Model
{
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
