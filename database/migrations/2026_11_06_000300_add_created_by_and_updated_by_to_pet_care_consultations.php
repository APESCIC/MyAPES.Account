<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who created and last edited each consultation (#538); older rows stay null.
     */
    public function up(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
