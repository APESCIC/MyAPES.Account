<?php

namespace App\Core\Extensions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime enablement row for an organisation-area module (#283).
 *
 * Distinct from plugin enablement (`module_plugins`).
 */
class OrganisationModule extends Model
{
    protected $table = 'organisation_modules';

    protected $fillable = [
        'slug',
        'enabled',
        'uploads_enabled',
        'enabled_at',
        'enabled_by',
        'disabled_at',
        'disabled_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'uploads_enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
