<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one shared admin settings store (#732). Secret values are encrypted
 * with the app key; v0.62's integrations registry (#634) reuses this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64);
            $table->string('key', 64);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
