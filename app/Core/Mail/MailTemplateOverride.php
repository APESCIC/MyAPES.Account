<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-edited subject and intro wording for one template and locale (#361).
 * Plain text only; the Blade view and lang files stay the source of truth.
 */
#[Fillable(['template_key', 'locale', 'subject', 'intro', 'lock_version', 'updated_by'])]
class MailTemplateOverride extends Model
{
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
