<?php

use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staff email permissions (#500): staff.messages.send (own module),
 * staff.messages.send.all (grantable) and staff.messages.history.view.
 * Named like staff.clients.* so PermissionNaming accepts them.
 * The matrix in AuthorizationProfile drives the role links; this seeds the
 * rows on installs that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $names = [
            'staff.messages.send',
            'staff.messages.send.all',
            'staff.messages.history.view',
        ];

        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'is_code_owned' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Ensure AuthorizationProfile rebuilds after the matrix gained these keys.
        app(AuthorizationProfile::class)->flushRuntimeCache();
        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }

    public function down(): void
    {
        $names = [
            'staff.messages.send',
            'staff.messages.send.all',
            'staff.messages.history.view',
        ];

        DB::table('role_has_permissions')
            ->whereIn(
                'permission_id',
                DB::table('permissions')
                    ->where('guard_name', 'web')
                    ->whereIn('name', $names)
                    ->select('id'),
            )
            ->delete();

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->delete();

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }
};
