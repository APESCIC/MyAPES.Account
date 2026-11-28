<?php

use App\Services\AuthorizationMetadataSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Security and Public Profiles permissions (#952).
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $permissions = [
        'admin.security.view',
        'admin.security.manage',
        'admin.public-profiles.view',
        'admin.public-profiles.manage',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'is_code_owned' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }

    public function down(): void
    {
        // Authorization metadata downgrades are handled manually.
    }
};
