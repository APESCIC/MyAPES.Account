<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('slug', 64);
            $table->string('version', 32);
            $table->string('previous_version', 32)->nullable();
            $table->timestamp('version_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'slug']);
        });

        Schema::create('extension_version_history', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('slug', 64);
            $table->string('from_version', 32)->nullable();
            $table->string('to_version', 32);
            $table->timestamp('recorded_at');

            $table->index(['kind', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_version_history');
        Schema::dropIfExists('extension_versions');
    }
};
