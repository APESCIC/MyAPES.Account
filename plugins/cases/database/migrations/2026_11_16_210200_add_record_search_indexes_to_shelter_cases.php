<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Search indexes for cases (#531).
 */
return new class extends Migration
{
    public function up(): void
    {
        // assigned_to already has a foreign-key index; do not add or drop it.
        SearchIndexMigration::addIndex('shelter_cases', 'shelter_cases_sub_core_status_index', function (Blueprint $table): void {
            $table->index(['sub_core_key', 'status'], 'shelter_cases_sub_core_status_index');
        });
        SearchIndexMigration::addIndex('shelter_cases', 'shelter_cases_title_index', function (Blueprint $table): void {
            $table->index('title', 'shelter_cases_title_index');
        });
        SearchIndexMigration::addFullText('shelter_cases', 'shelter_cases_title_fulltext', 'title');
    }

    public function down(): void
    {
        SearchIndexMigration::dropFullText('shelter_cases', 'shelter_cases_title_fulltext');
        SearchIndexMigration::dropIndex('shelter_cases', 'shelter_cases_sub_core_status_index', function (Blueprint $table): void {
            $table->dropIndex('shelter_cases_sub_core_status_index');
        });
        SearchIndexMigration::dropIndex('shelter_cases', 'shelter_cases_title_index', function (Blueprint $table): void {
            $table->dropIndex('shelter_cases_title_index');
        });
    }
};
