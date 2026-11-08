<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an attachment to the reply or case update it was added with (#544, #545).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->nullableMorphs('context');
        });
    }

    public function down(): void
    {
        Schema::table('support_attachments', function (Blueprint $table): void {
            $table->dropMorphs('context');
        });
    }
};
