<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virus scan state for each attachment (#550). Files uploaded before scanning
 * existed are "not_scanned".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->string('scan_status', 16)->default('not_scanned')->after('visibility');
            $table->timestamp('scanned_at')->nullable()->after('scan_status');
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->dropIndex(['scan_status']);
            $table->dropColumn(['scan_status', 'scanned_at']);
        });
    }
};
