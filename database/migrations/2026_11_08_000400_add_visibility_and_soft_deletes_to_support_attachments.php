<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-only attachments and soft deletion (#549).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('public')->after('kind');
            $table->foreignId('deleted_by')->nullable()->after('visibility')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
            $table->dropColumn('visibility');
        });
    }
};
