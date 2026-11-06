<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Pet Care Clinic ticket a consultation was created from (#540).
     */
    public function up(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->foreignId('support_ticket_id')->nullable()->after('pet_profile_id')->constrained('support_tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('support_ticket_id');
        });
    }
};
