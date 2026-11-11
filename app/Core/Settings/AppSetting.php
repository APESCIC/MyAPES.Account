<?php

namespace App\Core\Settings;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored admin setting (#732). Secret values hold ciphertext only; read
 * them through AppSettings, never directly.
 */
#[Fillable(['group', 'key', 'value', 'is_secret', 'updated_by'])]
class AppSetting extends Model
{
    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'updated_by' => 'integer',
        ];
    }
}
