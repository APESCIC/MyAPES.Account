<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks ticket messages and case updates that arrived as email replies (#582).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->boolean('via_email')->default(false)->after('is_staff_note');
        });

        Schema::table('case_updates', function (Blueprint $table): void {
            $table->boolean('via_email')->default(false)->after('visibility');
        });
    }

    public function down(): void
    {
        foreach (['support_ticket_messages', 'case_updates'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'via_email')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('via_email');
                });
            }
        }
    }
};
