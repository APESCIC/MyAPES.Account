<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_role_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recruitment_role_id')->constrained('recruitment_roles')->cascadeOnDelete();
            $table->string('label', 255);
            $table->string('help_text', 500)->nullable();
            $table->string('answer_type', 16);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['recruitment_role_id', 'position']);
        });

        Schema::table('recruitment_applications', function (Blueprint $table): void {
            $table->json('answers')->nullable()->after('statement');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_applications', function (Blueprint $table): void {
            $table->dropColumn('answers');
        });

        Schema::dropIfExists('recruitment_role_questions');
    }
};
