<?php

use App\Services\AuthorizationMetadataSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client internal notes (#523) and staff.clients.view for the profile hub (#521).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'name' => 'staff.clients.view',
            'guard_name' => 'web',
            'is_code_owned' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('name', 'staff.clients.view')
            ->where('guard_name', 'web')
            ->value('id');
        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['student', 'volunteer', 'staff', 'administrator'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(AuthorizationMetadataSynchronizer::class)->synchronize();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
    }
};
