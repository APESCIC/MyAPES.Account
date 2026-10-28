<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Notifications\Auth\EmailChangeCompletedNotification;
use App\Notifications\Auth\EmailChangeConfirmNotification;
use App\Notifications\Auth\EmailChangeStartedNotification;
use App\Notifications\Auth\PasskeyAddedNotification;
use App\Notifications\Auth\PasskeyRemovedNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthEmailBrandKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_send_verify_and_reset_use_branded_notifications(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'Brand Kit User',
            'email' => 'brand.kit@example.com',
        ]);

        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('test-reset-token');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification): bool {
                return $notification->token === 'test-reset-token';
            },
        );
    }

    public function test_verify_reset_and_password_changed_render_with_brand_kit(): void
    {
        $user = User::factory()->create([
            'name' => 'Render User',
            'email' => 'render.user@example.com',
        ]);

        $this->assertMailUsesBrandKit(
            (new VerifyEmailNotification)->toMail($user),
            __('mail.auth.verify.subject'),
            [
                __('mail.auth.verify.intro'),
                __('mail.auth.verify.action'),
                __('terms.app_name'),
                __('mail.brand.footer.help'),
            ],
            ['Correct-horse', 'totp', 'secret'],
        );

        $this->assertMailUsesBrandKit(
            (new ResetPasswordNotification('opaque-reset-token'))->toMail($user),
            __('mail.auth.reset.subject'),
            [
                __('mail.auth.reset.intro'),
                __('mail.auth.reset.action'),
                __('terms.app_name'),
                __('mail.brand.footer.help'),
            ],
            ['Correct-horse', 'totp', 'secret'],
        );

        $this->assertMailUsesBrandKit(
            (new PasswordChangedNotification)->toMail($user),
            __('mail.auth.password_changed.subject'),
            [
                __('mail.auth.password_changed.intro'),
                __('mail.auth.password_changed.action'),
                __('terms.app_name'),
                __('mail.brand.footer.help'),
            ],
            ['Correct-horse', 'secret', 'totp'],
        );
    }

    public function test_security_event_stubs_render_without_secrets_in_subject(): void
    {
        $user = User::factory()->create(['name' => 'Stub User']);

        $stubs = [
            new TwoFactorEnabledNotification,
            new TwoFactorDisabledNotification,
            new PasskeyAddedNotification,
            new PasskeyRemovedNotification,
            new EmailChangeStartedNotification,
            new EmailChangeConfirmNotification(
                recipientName: 'Stub User',
                confirmUrl: 'https://example.test/email/change/confirm/1/hash',
                newEmail: 'new@example.com',
            ),
            new EmailChangeCompletedNotification,
        ];

        foreach ($stubs as $notification) {
            $mail = $notification->toMail($user);
            $rendered = $mail->render();

            $this->assertStringContainsString('MyAPES Account', (string) $mail->subject);
            $this->assertStringNotContainsString('secret', strtolower((string) $mail->subject));
            $this->assertStringNotContainsString('totp', strtolower((string) $mail->subject));
            $this->assertStringContainsString(__('terms.app_name'), $rendered);
            $this->assertStringContainsString(__('mail.brand.footer.help'), $rendered);
            $this->assertStringContainsString(url('/logos/myapes-header-light-600x128.png'), $rendered);
        }
    }

    public function test_auth_mail_renders_on_the_shared_brand_message(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/mail/auth/message.blade.php'));

        foreach (glob(resource_path('views/mail/auth/*.blade.php')) ?: [] as $view) {
            $this->assertStringContainsString(
                "@component('mail.brand.message')",
                (string) file_get_contents($view),
                basename($view).' must use the shared brand message.',
            );
        }
    }

    public function test_registered_auth_templates_keep_secrets_out_of_subjects_and_bodies(): void
    {
        $user = User::factory()->create(['name' => 'Secret Free']);

        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            if (! str_starts_with($template->key, 'auth.')) {
                continue;
            }

            $notification = $template->sampleNotification($user);
            $this->assertInstanceOf(ShouldQueue::class, $notification, $template->key);

            $mail = $notification->toMail($user);
            $subject = strtolower((string) $mail->subject);
            $html = $mail->render();

            $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null, $template->key);
            $this->assertStringContainsString(__('mail.brand.footer.help'), $html, $template->key);
            $this->assertStringNotContainsString('sample-preview-token', $subject, $template->key);
            $this->assertStringNotContainsString('password:', $subject, $template->key);
            $this->assertStringNotContainsString('totp', strtolower($html), $template->key);
            $this->assertStringNotContainsString((string) $user->password, $html, $template->key);
        }
    }

    public function test_password_changed_notification_is_assertable(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->notify(new PasswordChangedNotification);

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    /**
     * @param  list<string>  $mustContain
     * @param  list<string>  $mustNotContain
     */
    private function assertMailUsesBrandKit(
        object $mail,
        string $expectedSubject,
        array $mustContain,
        array $mustNotContain,
    ): void {
        $this->assertSame($expectedSubject, $mail->subject);
        $this->assertSame(__('terms.app_name'), $mail->from[1] ?? null);

        $subjectLower = strtolower($expectedSubject);
        $this->assertStringNotContainsString('secret', $subjectLower);
        $this->assertStringNotContainsString('token=', $subjectLower);
        $this->assertStringNotContainsString('password:', $subjectLower);

        $html = $mail->render();

        foreach ($mustContain as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertStringContainsString(url('/logos/myapes-header-light-600x128.png'), $html);
        $this->assertStringContainsString(route('help'), $html);

        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }
}
