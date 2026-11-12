<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last imported IMAP UID per mailbox and folder, with its UIDVALIDITY
 * (#777), so each poll fetches only new mail and survives a restart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_mailbox_states', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 255);
            $table->string('folder', 255);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamps();

            $table->unique(['mailbox', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_mailbox_states');
    }
};
