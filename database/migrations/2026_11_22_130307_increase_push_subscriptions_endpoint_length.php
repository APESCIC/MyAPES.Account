<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');
        $maxLength = PushSubscription::ENDPOINT_MAX_LENGTH;

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        $endpoint = collect(Schema::connection($connection)->getColumns($table))
            ->firstWhere('name', 'endpoint');

        if (is_array($endpoint) && (int) ($endpoint['length'] ?? 0) >= $maxLength) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['endpoint']);
        });

        Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($maxLength): void {
            $blueprint->string('endpoint', $maxLength)
                ->charset('ascii')
                ->unique()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['endpoint']);
        });

        Schema::connection($connection)->table($table, function (Blueprint $blueprint): void {
            $blueprint->string('endpoint', 500)
                ->unique()
                ->change();
        });
    }
};
