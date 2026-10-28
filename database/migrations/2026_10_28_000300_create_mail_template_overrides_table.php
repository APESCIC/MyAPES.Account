<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_template_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 80);
            $table->string('locale', 10);
            $table->string('subject', 200)->nullable();
            $table->text('intro')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_template_overrides');
    }
};
