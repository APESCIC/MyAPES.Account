<?php

namespace Plugins\Cases\Models;

use App\Core\Accounts\User;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shelter_case_id',
    'user_id',
    'body',
    'visibility',
])]
class CaseUpdate extends Model
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    protected function casts(): array
    {
        return [
            'body' => SanitisedHtml::class,
        ];
    }

    public function shelterCase(): BelongsTo
    {
        return $this->belongsTo(ShelterCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
