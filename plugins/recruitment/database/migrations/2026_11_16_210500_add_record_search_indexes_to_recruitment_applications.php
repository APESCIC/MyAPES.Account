<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Search indexes for recruitment applications (#531).
 */
return new class extends Migration
{
    public function up(): void
    {
        SearchIndexMigration::addIndex('recruitment_applications', 'recruitment_applications_status_index', function (Blueprint $table): void {
            $table->index('status', 'recruitment_applications_status_index');
        });
        SearchIndexMigration::addIndex('recruitment_applications', 'recruitment_applications_submitted_at_index', function (Blueprint $table): void {
            $table->index('submitted_at', 'recruitment_applications_submitted_at_index');
        });
        SearchIndexMigration::addIndex('recruitment_applications', 'recruitment_applications_status_submitted_index', function (Blueprint $table): void {
            $table->index(['status', 'submitted_at'], 'recruitment_applications_status_submitted_index');
        });
    }

    public function down(): void
    {
        SearchIndexMigration::dropIndex('recruitment_applications', 'recruitment_applications_status_index', function (Blueprint $table): void {
            $table->dropIndex('recruitment_applications_status_index');
        });
        SearchIndexMigration::dropIndex('recruitment_applications', 'recruitment_applications_submitted_at_index', function (Blueprint $table): void {
            $table->dropIndex('recruitment_applications_submitted_at_index');
        });
        SearchIndexMigration::dropIndex('recruitment_applications', 'recruitment_applications_status_submitted_index', function (Blueprint $table): void {
            $table->dropIndex('recruitment_applications_status_submitted_index');
        });
    }
};
