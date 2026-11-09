<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replies received at the communications mailbox (#579). The raw message is
 * a file on the private disk, pruned after 30 days; the row keeps metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->char('message_id_hash', 64)->unique();
            $table->string('message_id', 191)->nullable();
            $table->string('source', 16)->default('imap');
            $table->string('from_address', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->char('from_hash', 64)->nullable()->index();
            $table->string('from_domain', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('raw_path', 255)->nullable();
            $table->unsignedInteger('raw_size')->default(0);
            $table->string('status', 16)->default('received');
            $table->string('reason', 48)->nullable();
            $table->string('detail', 500)->nullable();
            $table->string('plugin', 32)->nullable();
            $table->string('module_slug', 64)->nullable();
            $table->char('record_prefix', 1)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('posted');
            $table->unsignedSmallInteger('attachments_stored')->default(0);
            $table->unsignedSmallInteger('attachments_rejected')->default(0);
            $table->string('spf', 16)->nullable();
            $table->string('dkim', 16)->nullable();
            $table->string('dmarc', 16)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('notice_sent')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('raw_pruned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['record_prefix', 'record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
