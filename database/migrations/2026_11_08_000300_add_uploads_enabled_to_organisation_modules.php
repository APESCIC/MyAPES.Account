<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admins can turn ticket and case uploads off per module (#548).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisation_modules', function (Blueprint $table): void {
            $table->boolean('uploads_enabled')->default(true)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organisation_modules', function (Blueprint $table): void {
            $table->dropColumn('uploads_enabled');
        });
    }
};
