<?php

use App\Core\Search\SearchIndexMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microchip field and search indexes for pet profiles (#531 / #529).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('pet_profiles', 'microchip')) {
                $table->string('microchip', 64)->nullable()->after('name');
            }
        });

        SearchIndexMigration::addIndex('pet_profiles', 'pet_profiles_name_index', function (Blueprint $table): void {
            $table->index('name', 'pet_profiles_name_index');
        });
        SearchIndexMigration::addIndex('pet_profiles', 'pet_profiles_microchip_index', function (Blueprint $table): void {
            $table->index('microchip', 'pet_profiles_microchip_index');
        });
        SearchIndexMigration::addIndex('pet_profiles', 'pet_profiles_domain_name_index', function (Blueprint $table): void {
            $table->index(['service_domain', 'name'], 'pet_profiles_domain_name_index');
        });
        SearchIndexMigration::addFullText('pet_profiles', 'pet_profiles_name_fulltext', 'name');
    }

    public function down(): void
    {
        SearchIndexMigration::dropFullText('pet_profiles', 'pet_profiles_name_fulltext');
        SearchIndexMigration::dropIndex('pet_profiles', 'pet_profiles_name_index', function (Blueprint $table): void {
            $table->dropIndex('pet_profiles_name_index');
        });
        SearchIndexMigration::dropIndex('pet_profiles', 'pet_profiles_microchip_index', function (Blueprint $table): void {
            $table->dropIndex('pet_profiles_microchip_index');
        });
        SearchIndexMigration::dropIndex('pet_profiles', 'pet_profiles_domain_name_index', function (Blueprint $table): void {
            $table->dropIndex('pet_profiles_domain_name_index');
        });

        Schema::table('pet_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('pet_profiles', 'microchip')) {
                $table->dropColumn('microchip');
            }
        });
    }
};
