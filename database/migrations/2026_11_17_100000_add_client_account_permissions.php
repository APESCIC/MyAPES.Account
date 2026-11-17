<?php

use App\Services\AuthorizationMetadataSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client account management permissions (#613): client.search (View accounts
 * pack: staff, volunteers, students), client.manage (Manage accounts pack:
 * staff) and client.sensitive (administrators only). The matrix in
 * AuthorizationProfile drives the role links; this seeds the rows on installs
 * that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['client.search', 'client.manage', 'client.sensitive'] as $name) {
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
                    ->whereIn('name', ['client.search', 'client.manage', 'client.sensitive'])
                    ->select('id'),
            )
            ->delete();

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['client.search', 'client.manage', 'client.sensitive'])
            ->delete();

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }
};
