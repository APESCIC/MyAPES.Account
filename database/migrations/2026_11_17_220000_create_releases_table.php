<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive projection of per-release changelog files (#705).
 * Older app code keeps reading resources/data/releases.json until #707.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('slug')->unique();
            $table->string('previous_version')->nullable();
            $table->date('released_at');
            $table->string('channel');
            $table->string('type');
            $table->string('title');
            $table->boolean('is_current')->default(false);
            $table->boolean('is_published')->default(false);
            $table->text('summary');
            $table->json('changes');
            $table->json('affected_areas');
            $table->json('categories');
            $table->json('audiences');
            $table->text('version_rationale');
            $table->json('validation');
            $table->json('known_limitations');
            $table->text('rollback');
            $table->text('provenance');
            $table->json('references');
            $table->string('source_checksum', 64)->nullable();
            $table->string('source_path')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'released_at']);
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
