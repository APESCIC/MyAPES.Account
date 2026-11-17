<?php

use App\Services\AuthorizationMetadataSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client account management permissions (#613): staff.clients.search (View
 * accounts pack: staff, volunteers, students), staff.clients.manage (Manage
 * accounts pack: staff) and staff.clients.sensitive (administrators only).
 * Named like staff.clients.create/view so PermissionNaming accepts them.
 * The matrix in AuthorizationProfile drives the role links; this seeds the
 * rows on installs that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['staff.clients.search', 'staff.clients.manage', 'staff.clients.sensitive'] as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
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
        DB::table('role_has_permissions')
            ->whereIn(
                'permission_id',
                DB::table('permissions')
                    ->where('guard_name', 'web')
                    ->whereIn('name', ['staff.clients.search', 'staff.clients.manage', 'staff.clients.sensitive'])
                    ->select('id'),
            )
            ->delete();

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['staff.clients.search', 'staff.clients.manage', 'staff.clients.sensitive'])
            ->delete();

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }
};
