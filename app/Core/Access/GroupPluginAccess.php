<?php

namespace App\Core\Access;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin-set switch in the group access matrix (#460): whether a protected
 * group may use a plugin in an organisation module. No row means the group
 * keeps its default access.
 */
class GroupPluginAccess extends Model
{
    protected $table = 'group_plugin_access';

    protected $fillable = [
        'role_name',
        'sub_core_key',
        'module_key',
        'allowed',
        'updated_by',
    ];

    protected static function booted(): void
    {
        $flush = static function (): void {
            if (app()->resolved(GroupAccessMatrix::class)) {
                app(GroupAccessMatrix::class)->flush();
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }
}
