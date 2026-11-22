<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->unsignedTinyInteger('consecutive_failures')->default(0)->after('content_encoding');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->dropColumn('consecutive_failures');
        });
    }
};