<?php

namespace Plugins\Recruitment\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extra question on a role's application form (#469).
 */
#[Fillable([
    'label',
    'help_text',
    'answer_type',
    'required',
    'position',
])]
class RecruitmentRoleQuestion extends Model
{
    public const TYPE_SHORT = 'short';

    public const TYPE_LONG = 'long';

    public const TYPES = [self::TYPE_SHORT, self::TYPE_LONG];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function recruitmentRole(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRole::class);
    }

    public function maxLength(): int
    {
        return $this->answer_type === self::TYPE_LONG ? 5000 : 255;
    }
}
