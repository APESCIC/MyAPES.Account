<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Search indexes for users (#531).
 */
return new class extends Migration
{
    public function up(): void
    {
        SearchIndexMigration::addIndex('users', 'users_name_search_index', function (Blueprint $table): void {
            $table->index('name', 'users_name_search_index');
        });
        SearchIndexMigration::addFullText('users', 'users_name_email_fulltext', 'name, email');
    }

    public function down(): void
    {
        SearchIndexMigration::dropFullText('users', 'users_name_email_fulltext');
        SearchIndexMigration::dropIndex('users', 'users_name_search_index', function (Blueprint $table): void {
            $table->dropIndex('users_name_search_index');
        });
    }
};
