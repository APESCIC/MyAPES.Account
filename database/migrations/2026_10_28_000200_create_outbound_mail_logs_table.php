<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_mail_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 80)->nullable()->index();
            $table->string('notification_type', 191);
            $table->string('mailer', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('recipient_hash', 64)->nullable()->index();
            $table->string('recipient_domain', 191)->nullable();
            $table->string('status', 16);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_mail_logs');
    }
};
