<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 16);
            $table->text('summary');
            $table->timestamp('called_at');
            $table->timestamps();

            $table->index(['caller_id', 'called_at']);
            $table->index('called_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
