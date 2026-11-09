<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message-IDs of ticket and case emails, for matching replies (#578).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_reply_threads', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id', 191)->unique();
            $table->char('record_prefix', 1);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['record_prefix', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_reply_threads');
    }
};
