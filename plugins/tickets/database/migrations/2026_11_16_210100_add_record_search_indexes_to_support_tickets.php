<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Search indexes for tickets (#531).
 */
return new class extends Migration
{
    public function up(): void
    {
        // assigned_to already has a foreign-key index; do not add or drop it.
        SearchIndexMigration::addIndex('support_tickets', 'support_tickets_sub_core_status_index', function (Blueprint $table): void {
            $table->index(['sub_core_key', 'status'], 'support_tickets_sub_core_status_index');
        });
        SearchIndexMigration::addIndex('support_tickets', 'support_tickets_subject_index', function (Blueprint $table): void {
            $table->index('subject', 'support_tickets_subject_index');
        });
        SearchIndexMigration::addFullText('support_tickets', 'support_tickets_subject_fulltext', 'subject');
    }

    public function down(): void
    {
        SearchIndexMigration::dropFullText('support_tickets', 'support_tickets_subject_fulltext');
        SearchIndexMigration::dropIndex('support_tickets', 'support_tickets_sub_core_status_index', function (Blueprint $table): void {
            $table->dropIndex('support_tickets_sub_core_status_index');
        });
        SearchIndexMigration::dropIndex('support_tickets', 'support_tickets_subject_index', function (Blueprint $table): void {
            $table->dropIndex('support_tickets_subject_index');
        });
    }
};
