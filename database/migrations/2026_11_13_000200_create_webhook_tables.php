<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing webhooks (#641, #642): endpoints with an encrypted signing secret,
 * one delivery per event and endpoint, and a row for every attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('url', 500);
            $table->string('organisation', 32);
            $table->json('events');
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->index();
            $table->string('event', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('replay_of_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
