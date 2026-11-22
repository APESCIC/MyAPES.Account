<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('subscription_hash', 64)->index();
            $table->string('notification_type', 191);
            $table->unsignedSmallInteger('result_code')->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};