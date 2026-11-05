<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client records held by staff-side accounts are archived, never deleted (#514).
     *
     * @var list<string>
     */
    private array $tables = [
        'support_tickets',
        'pet_profiles',
        'shelter_cases',
        'pet_care_consultations',
        'recruitment_applications',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('client_records_archived_at')->nullable();
            $table->timestamp('client_records_notice_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['client_records_archived_at', 'client_records_notice_dismissed_at']);
        });

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            });
        }
    }
};
