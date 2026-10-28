<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\OutboundMailLog;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\PendingFirstLoginChaseNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OutboundMailLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sent_email_leaves_one_metadata_row(): void
    {
        $user = User::factory()->create(['email' => 'Person.Name@Example.ORG']);

        $user->notify(new PendingFirstLoginChaseNotification($user));

        $entry = OutboundMailLog::query()->sole();
        $this->assertSame('accounts.pending_first_login_chase', $entry->template_key);
        $this->assertSame(PendingFirstLoginChaseNotification::class, $entry->notification_type);
        $this->assertSame(OutboundMailLog::STATUS_SENT, $entry->status);
        $this->assertSame((string) config('mail.default'), $entry->mailer);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('example.org', $entry->recipient_domain);
        $this->assertSame(OutboundMailLog::hashRecipient('person.name@example.org'), $entry->recipient_hash);
        $this->assertNotNull($entry->created_at);
    }

    public function test_the_log_never_stores_addresses_subjects_bodies_links_or_tokens(): void
    {
        $user = User::factory()->create(['email' => 'secret.person@example.com']);

        $user->notify(new ResetPasswordNotification('very-secret-reset-token'));
        $user->notify(new VerifyEmailNotification);

        $columns = Schema::getColumnListing('outbound_mail_logs');
        foreach (['subject', 'body', 'html', 'content', 'to', 'email', 'url', 'token'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $stored = json_encode(OutboundMailLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret.person', $stored);
        $this->assertStringNotContainsString('very-secret-reset-token', $stored);
        $this->assertStringNotContainsString('signature', $stored);
        $this->assertStringNotContainsString(__('mail.auth.reset.subject'), $stored);
        $this->assertSame(2, OutboundMailLog::query()->count());
    }

    public function test_route_only_recipients_are_logged_without_a_user(): void
    {
        Notification::route('mail', 'routed@example.net')
            ->notify(new PendingFirstLoginChaseNotification(User::factory()->create()));

        $entry = OutboundMailLog::query()->sole();
        $this->assertNull($entry->user_id);
        $this->assertSame('example.net', $entry->recipient_domain);
    }

    public function test_failed_mail_is_recorded_as_failed(): void
    {
        $user = User::factory()->create();

        event(new NotificationFailed($user, new VerifyEmailNotification, 'mail', []));
        event(new NotificationFailed($user, new VerifyEmailNotification, 'database', []));

        $entry = OutboundMailLog::query()->sole();
        $this->assertSame(OutboundMailLog::STATUS_FAILED, $entry->status);
        $this->assertSame('auth.verify_email', $entry->template_key);
    }

    public function test_in_app_only_channels_are_not_logged(): void
    {
        $user = User::factory()->create();

        Notification::sendNow($user, new PendingFirstLoginChaseNotification($user), ['database']);

        $this->assertSame(0, OutboundMailLog::query()->count());
    }

    public function test_prune_removes_entries_past_retention(): void
    {
        config(['myapes.mail.log_retention_days' => 30]);
        $old = OutboundMailLog::query()->create($this->row(['created_at' => now()->subDays(31)]));
        $fresh = OutboundMailLog::query()->create($this->row(['created_at' => now()->subDays(29)]));

        $this->artisan('mail:prune-log')
            ->expectsOutputToContain('Deleted 1 outbound mail log record(s) older than 30 days.')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($fresh);
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('mail:prune-log')
            ->assertSuccessful();
    }

    public function test_admins_see_the_log_without_full_addresses(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $recipient = User::factory()->create(['email' => 'hidden.person@example.com']);
        $recipient->notify(new PendingFirstLoginChaseNotification($administrator));

        $this->actingAs($administrator)
            ->get(route('admin.emails.log'))
            ->assertOk()
            ->assertSeeText(__('admin.emails.log_heading'))
            ->assertSeeText(__('mail.templates.accounts.pending_first_login_chase.name'))
            ->assertSeeText('example.com')
            ->assertDontSeeText('hidden.person');

        $this->actingAs($administrator)
            ->get(route('admin.emails.index'))
            ->assertSee('href="'.route('admin.emails.log').'"', false);
    }

    public function test_staff_cannot_open_the_log(): void
    {
        $this->get(route('admin.emails.log'))->assertRedirect('/login');

        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->actingAs($staff)->get(route('admin.emails.log'))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'template_key' => 'auth.verify_email',
            'notification_type' => VerifyEmailNotification::class,
            'mailer' => 'array',
            'recipient_hash' => OutboundMailLog::hashRecipient('a@example.com'),
            'recipient_domain' => 'example.com',
            'status' => OutboundMailLog::STATUS_SENT,
            'created_at' => now(),
        ], $overrides);
    }
}
