<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether replies by email are on for one plugin in one service (#585).
 */
#[Fillable(['sub_core_key', 'plugin', 'enabled', 'updated_by'])]
class InboundReplySetting extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
