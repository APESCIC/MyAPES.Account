<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_plugin_access', function (Blueprint $table): void {
            $table->id();
            $table->string('role_name', 32);
            $table->string('sub_core_key', 64);
            $table->string('module_key', 64);
            $table->boolean('allowed');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_name', 'sub_core_key', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_plugin_access');
    }
};
