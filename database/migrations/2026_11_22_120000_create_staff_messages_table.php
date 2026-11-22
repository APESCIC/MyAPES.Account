<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-to-user email campaigns (#501–#509).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_messages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 16);
            $table->string('subject');
            $table->text('body_html');
            $table->json('recipient_filter');
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('opted_out_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};