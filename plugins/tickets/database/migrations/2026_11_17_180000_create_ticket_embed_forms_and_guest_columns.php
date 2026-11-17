<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embeddable guest open-a-ticket forms (#681–#692): admin-managed forms,
 * nullable ticket ownership for unverified guests, and verify tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_embed_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('public_key', 64)->unique();
            $table->text('signing_secret');
            $table->boolean('is_active')->default(true);
            $table->json('enabled_services');
            $table->json('allowed_origins');
            $table->decimal('spam_score_threshold', 3, 2)->nullable();
            $table->text('confirmation_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('user_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->timestamp('guest_email_verified_at')->nullable()->after('guest_email');
            $table->foreignId('embed_form_id')->nullable()->after('guest_email_verified_at')
                ->constrained('ticket_embed_forms')->nullOnDelete();
            $table->timestamp('staff_notified_at')->nullable()->after('embed_form_id');
            $table->string('guest_consent_method', 32)->nullable()->after('staff_notified_at');
            $table->string('guest_consent_policy_version', 64)->nullable()->after('guest_consent_method');
            $table->timestamp('guest_consented_at')->nullable()->after('guest_consent_policy_version');
        });

        Schema::create('ticket_embed_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['support_ticket_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_embed_verifications');

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('embed_form_id');
            $table->dropColumn([
                'guest_name',
                'guest_email',
                'guest_email_verified_at',
                'staff_notified_at',
                'guest_consent_method',
                'guest_consent_policy_version',
                'guest_consented_at',
            ]);
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::dropIfExists('ticket_embed_forms');
    }
};
