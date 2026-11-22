<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_push_preferences', function (Blueprint $table): void {
            $table->boolean('email_enabled')->default(true)->after('enabled');
        });

        Schema::create('user_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('quiet_hours_timezone', 64)->default('Europe/London');
            $table->timestamps();
        });

        Schema::create('user_push_division_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('division_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'division_key']);
        });

        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->string('device_browser', 64)->nullable()->after('consecutive_failures');
            $table->string('device_system', 64)->nullable()->after('device_browser');
            $table->string('user_agent', 512)->nullable()->after('device_system');
            $table->timestamp('last_used_at')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('user_push_preferences', function (Blueprint $table): void {
            $table->dropColumn('email_enabled');
        });

        Schema::dropIfExists('user_push_division_preferences');
        Schema::dropIfExists('user_notification_settings');

        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->dropColumn(['device_browser', 'device_system', 'user_agent', 'last_used_at']);
        });
    }
};
