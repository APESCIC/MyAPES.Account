<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_contact_preferences', function (Blueprint $table): void {
            $table->boolean('marketing_email')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('user_contact_preferences', function (Blueprint $table): void {
            $table->dropColumn('marketing_email');
        });
    }
};