<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-assisted registration consent (#673), client.create (#672), and 72-hour set-password tokens (#671).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->foreignId('registration_consent_staff_id')
                ->nullable()
                ->after('registration_consented_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('staff_assisted_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'name' => 'client.create',
            'guard_name' => 'web',
            'is_code_owned' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')->where('name', 'client.create')->where('guard_name', 'web')->value('id');
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
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assisted_password_reset_tokens');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('registration_consent_staff_id');
            $table->string('email')->nullable(false)->change();
        });
    }
};
