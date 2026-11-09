<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service switch for replies by email (#585). No row means on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_reply_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('sub_core_key', 64);
            $table->string('plugin', 32);
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sub_core_key', 'plugin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_reply_settings');
    }
};
