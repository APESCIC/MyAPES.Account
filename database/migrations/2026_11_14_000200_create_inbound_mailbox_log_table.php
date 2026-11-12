<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recent connection log on Admin → Emails → Import (#780): tests and
 * polls with their step, failure type, time and counts. It never holds a
 * host, username, password, IMAP line or message content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_mailbox_log', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('result', 40);
            $table->string('step', 16)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('fetched')->nullable();
            $table->unsignedSmallInteger('stored')->nullable();
            $table->unsignedSmallInteger('duplicates')->nullable();
            $table->unsignedSmallInteger('failed')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_mailbox_log');
    }
};
