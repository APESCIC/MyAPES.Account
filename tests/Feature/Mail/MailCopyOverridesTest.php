<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\MailCopyOverrides;
use App\Core\Mail\MailTemplateOverride;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Tests\TestCase;

class MailCopyOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_overrides_subject_and_intro_and_mail_uses_them(): void
    {
        $superAdmin = $this->superAdmin();
        $recipient = User::factory()->create();

        $this->actingAs($superAdmin)
            ->put(route('admin.emails.copy.update', 'auth.password_changed'), [
                'subject' => 'Heads up: your MyAPES Account password changed',
                'intro' => 'We changed nothing else, but your password is new.',
                'lock_version' => 0,
            ])
            ->assertRedirect(route('admin.emails.show', 'auth.password_changed'))
            ->assertSessionHas('status', __('admin.flash.email_copy_saved'));

        $mail = (new PasswordChangedNotification)->toMail($recipient);
        $this->assertSame('Heads up: your MyAPES Account password changed', $mail->subject);
        $this->assertStringContainsString('We changed nothing else, but your password is new.', $mail->render());
        $this->assertStringContainsString(__('mail.auth.password_changed.outro'), $mail->render());

        $this->actingAs($superAdmin)
            ->get(route('admin.emails.show', 'auth.password_changed'))
            ->assertOk()
            ->assertSee('data-mail-copy', false)
            ->assertSeeText('Heads up: your MyAPES Account password changed')
            ->assertSeeText('Your MyAPES Account password was changed')
            ->assertSeeText($superAdmin->name);

        $audit = AuditLog::query()->where('event', 'admin.emails.copy_updated')->sole();
        $this->assertSame($superAdmin->id, $audit->user_id);
        $this->assertSame('auth.password_changed', $audit->context['template']);
        $this->assertNull($audit->context['before']['subject']);
        $this->assertSame('Heads up: your MyAPES Account password changed', $audit->context['after']['subject']);
    }

    public function test_placeholders_must_come_from_the_shipped_wording(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->put(route('admin.emails.copy.update', 'recruitment.application_received'), [
                'subject' => 'Thanks for applying for :role',
                'lock_version' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($superAdmin)
            ->put(route('admin.emails.copy.update', 'recruitment.application_received'), [
                'subject' => 'Your :token for :role',
                'lock_version' => 1,
            ])
            ->assertSessionHasErrors('subject');

        $this->assertSame('Thanks for applying for :role', MailTemplateOverride::query()->sole()->subject);
        $this->assertSame('Thanks for applying for Keeper', __('recruitment::mail.received.subject', ['role' => 'Keeper']));
    }

    public function test_html_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.emails.copy.update', 'auth.password_changed'), [
                'intro' => 'Click <a href="https://evil.example">here</a>',
                'lock_version' => 0,
            ])
            ->assertSessionHasErrors('intro');

        $this->assertSame(0, MailTemplateOverride::query()->count());
    }

    public function test_stale_edits_are_rejected_by_the_lock_version(): void
    {
        $superAdmin = $this->superAdmin();
        $route = route('admin.emails.copy.update', 'auth.password_changed');

        $this->actingAs($superAdmin)->put($route, ['subject' => 'First edit', 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($superAdmin)->put($route, ['subject' => 'Stale edit', 'lock_version' => 0])->assertSessionHasErrors('lock_version');

        $this->assertSame('First edit', MailTemplateOverride::query()->sole()->subject);
    }

    public function test_reset_restores_shipped_wording_and_is_audited(): void
    {
        $superAdmin = $this->superAdmin();
        $recipient = User::factory()->create();

        $this->actingAs($superAdmin)->put(route('admin.emails.copy.update', 'auth.password_changed'), [
            'subject' => 'Temporary subject',
            'lock_version' => 0,
        ]);
        $this->assertSame('Temporary subject', (new PasswordChangedNotification)->toMail($recipient)->subject);

        $this->actingAs($superAdmin)
            ->delete(route('admin.emails.copy.reset', 'auth.password_changed'), ['confirm_reset' => '1'])
            ->assertRedirect(route('admin.emails.show', 'auth.password_changed'))
            ->assertSessionHas('status', __('admin.flash.email_copy_reset'));

        $this->assertSame(0, MailTemplateOverride::query()->count());
        $this->assertSame('Your MyAPES Account password was changed', (new PasswordChangedNotification)->toMail($recipient)->subject);
        $this->assertSame('Temporary subject', AuditLog::query()->where('event', 'admin.emails.copy_reset')->sole()->context['before']['subject']);
    }

    public function test_queue_workers_pick_up_changes_before_each_job(): void
    {
        $overrides = app(MailCopyOverrides::class);
        $override = MailTemplateOverride::query()->create([
            'template_key' => 'auth.password_changed',
            'locale' => 'en_GB',
            'subject' => 'Worker subject',
            'lock_version' => 1,
        ]);
        cache()->forget('mail-copy-overrides');

        event(new JobProcessing('sync', new SyncJob(app(), '{}', 'sync', 'default')));
        $this->assertSame('Worker subject', __('mail.auth.password_changed.subject'));

        $override->delete();
        cache()->forget('mail-copy-overrides');
        $overrides->apply();
        $this->assertSame('Your MyAPES Account password was changed', __('mail.auth.password_changed.subject'));
    }

    public function test_view_only_admins_see_the_wording_but_cannot_change_it(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($administrator)
            ->get(route('admin.emails.show', 'auth.password_changed'))
            ->assertOk()
            ->assertSee('data-mail-copy', false)
            ->assertDontSee('action="'.route('admin.emails.copy.update', 'auth.password_changed').'"', false);

        $this->actingAs($administrator)
            ->put(route('admin.emails.copy.update', 'auth.password_changed'), ['subject' => 'Nope', 'lock_version' => 0])
            ->assertForbidden();
        $this->actingAs($administrator)
            ->delete(route('admin.emails.copy.reset', 'auth.password_changed'), ['confirm_reset' => '1'])
            ->assertForbidden();

        $this->assertSame(0, MailTemplateOverride::query()->count());
    }

    public function test_templates_without_editable_copy_cannot_be_overridden(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.emails.copy.update', 'recruitment.application_status'), ['subject' => 'Nope', 'lock_version' => 0])
            ->assertNotFound();
    }

    private function superAdmin(): User
    {
        return User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();
    }
}
