<?php

use App\Services\AuthorizationMetadataSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename short-lived client.* permissions to staff.clients.* so they match
 * PermissionNaming and the existing staff.clients.create/view abilities.
 * Safe when the earlier migration already inserted the new names (no-op) or
 * when a local database still has the old client.* rows.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const RENAMES = [
        'client.search' => 'staff.clients.search',
        'client.manage' => 'staff.clients.manage',
        'client.sensitive' => 'staff.clients.sensitive',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::RENAMES as $oldName => $newName) {
            $old = DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $oldName)
                ->first();

            $new = DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $newName)
                ->first();

            if ($old === null && $new === null) {
                DB::table('permissions')->insert([
                    'name' => $newName,
                    'guard_name' => 'web',
                    'is_code_owned' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            if ($old === null) {
                continue;
            }

            if ($new === null) {
                DB::table('permissions')
                    ->where('id', $old->id)
                    ->update([
                        'name' => $newName,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            $oldRoleIds = DB::table('role_has_permissions')
                ->where('permission_id', $old->id)
                ->pluck('role_id');

            foreach ($oldRoleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $new->id,
                    'role_id' => $roleId,
                ]);
            }

            DB::table('role_has_permissions')
                ->where('permission_id', $old->id)
                ->delete();

            DB::table('model_has_permissions')
                ->where('permission_id', $old->id)
                ->update(['permission_id' => $new->id]);

            DB::table('permissions')->where('id', $old->id)->delete();
        }

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }

    public function down(): void
    {
        $now = now();

        foreach (self::RENAMES as $oldName => $newName) {
            $new = DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $newName)
                ->first();

            if ($new === null) {
                continue;
            }

            $old = DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $oldName)
                ->first();

            if ($old === null) {
                DB::table('permissions')
                    ->where('id', $new->id)
                    ->update([
                        'name' => $oldName,
                        'updated_at' => $now,
                    ]);
            }
        }

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }
};
