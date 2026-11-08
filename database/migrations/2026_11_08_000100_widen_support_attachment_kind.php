<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support attachments gain the 'document' kind (#543).
 *
 * The enum becomes a short string so new kinds do not need a column change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->enum('kind', ['screenshot', 'screencast'])->change();
        });
    }
};
