<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consultations created by non-clinical users are kept and flagged for vet review (#539).
     */
    public function up(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->timestamp('vet_review_flagged_at')->nullable()->index();
            $table->timestamp('vet_reviewed_at')->nullable();
            $table->foreignId('vet_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_care_consultations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vet_reviewed_by');
            $table->dropColumn('vet_reviewed_at');
            $table->dropIndex(['vet_review_flagged_at']);
            $table->dropColumn('vet_review_flagged_at');
        });
    }
};
