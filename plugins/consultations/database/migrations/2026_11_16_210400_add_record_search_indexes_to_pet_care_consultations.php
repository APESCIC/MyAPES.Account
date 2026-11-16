<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Search indexes for consultations (#531).
 */
return new class extends Migration
{
    public function up(): void
    {
        SearchIndexMigration::addIndex('pet_care_consultations', 'pet_care_consultations_status_index', function (Blueprint $table): void {
            $table->index('status', 'pet_care_consultations_status_index');
        });
        SearchIndexMigration::addIndex('pet_care_consultations', 'pet_care_consultations_assigned_to_index', function (Blueprint $table): void {
            $table->index('assigned_to', 'pet_care_consultations_assigned_to_index');
        });
        SearchIndexMigration::addIndex('pet_care_consultations', 'pet_care_consultations_subject_index', function (Blueprint $table): void {
            $table->index('subject', 'pet_care_consultations_subject_index');
        });
        SearchIndexMigration::addFullText('pet_care_consultations', 'pet_care_consultations_subject_fulltext', 'subject');
    }

    public function down(): void
    {
        SearchIndexMigration::dropFullText('pet_care_consultations', 'pet_care_consultations_subject_fulltext');
        SearchIndexMigration::dropIndex('pet_care_consultations', 'pet_care_consultations_status_index', function (Blueprint $table): void {
            $table->dropIndex('pet_care_consultations_status_index');
        });
        SearchIndexMigration::dropIndex('pet_care_consultations', 'pet_care_consultations_assigned_to_index', function (Blueprint $table): void {
            $table->dropIndex('pet_care_consultations_assigned_to_index');
        });
        SearchIndexMigration::dropIndex('pet_care_consultations', 'pet_care_consultations_subject_index', function (Blueprint $table): void {
            $table->dropIndex('pet_care_consultations_subject_index');
        });
    }
};
