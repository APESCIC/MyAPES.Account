<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_state', 32)->default('active')->after('identity_type');
            $table->index('account_state');
        });

        // Default every row to active, then mark staff-created never-onboarded as profile-only.
        DB::table('users')->update(['account_state' => 'active']);

        DB::table('users')
            ->whereNotNull('registration_consent_staff_id')
            ->whereNull('onboarding_completed_at')
            ->update(['account_state' => 'profile_only']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_state']);
            $table->dropColumn('account_state');
        });
    }
};
