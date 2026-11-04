<?php

namespace App\Core\Extensions\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Installed version of a module or plugin package (#473).
 */
#[Fillable([
    'kind',
    'slug',
    'version',
    'previous_version',
    'version_updated_at',
])]
class ExtensionVersion extends Model
{
    public const KIND_MODULE = 'module';

    public const KIND_PLUGIN = 'plugin';

    protected function casts(): array
    {
        return [
            'version_updated_at' => 'datetime',
        ];
    }
}
