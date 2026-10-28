<?php

namespace Tests\Feature\AccountSecurity;

use App\Core\Accounts\User;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Events\PasskeyRegistered;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

/**
 * Mail-fake matrix for the #226 auth / security notification kit (#234).
 */
class AuthSecurityNotificationsMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_branded_auth_kit_covers_verify_reset_password_changed_and_mfa_events(): void
    {
        Notification::fake();

        $user = User::factory()->withoutMfa()->create([
            'name' => 'Matrix Mail User',
            'email' => 'matrix.mail@example.com',
        ]);

        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('matrix-reset-token');
        $user->notify(new PasswordChangedNotification);
        $user->notify(new TwoFactorEnabledNotification);
        $user->notify(new TwoFactorDisabledNotification);
        $user->notify(new PasskeyAddedNotification);
        $user->notify(new PasskeyRemovedNotification);
        $user->notify(new EmailChangeStartedNotification);
        $user->notify(new EmailChangeCompletedNotification);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification): bool {
                return $notification->token === 'matrix-reset-token';
            },
        );
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);
        Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
        Notification::assertSentTo($user, PasskeyAddedNotification::class);
        Notification::assertSentTo($user, PasskeyRemovedNotification::class);
        Notification::assertSentTo($user, EmailChangeStartedNotification::class);
        Notification::assertSentTo($user, EmailChangeCompletedNotification::class);
    }

    public function test_security_mail_subjects_are_branded_and_do_not_leak_secrets(): void
    {
        $user = User::factory()->create([
            'name' => 'Render Matrix',
            'email' => 'render.matrix@example.com',
        ]);

        $notifications = [
            new VerifyEmailNotification,
            new ResetPasswordNotification('opaque-token'),
            new PasswordChangedNotification,
            new TwoFactorEnabledNotification,
            new TwoFactorDisabledNotification,
            new PasskeyAddedNotification,
            new PasskeyRemovedNotification,
            new EmailChangeStartedNotification,
            new EmailChangeConfirmNotification(
                recipientName: 'Render Matrix',
                confirmUrl: 'https://example.test/email/change/confirm/1/hash',
                newEmail: 'pending.matrix@example.com',
            ),
            new EmailChangeCompletedNotification,
        ];

        foreach ($notifications as $notification) {
            $mail = $notification->toMail($user);
            $subject = (string) $mail->subject;
            $html = $mail->render();

            $this->assertStringContainsString('MyAPES Account', $subject);
            $this->assertStringNotContainsString('secret', strtolower($subject));
            $this->assertStringNotContainsString('totp', strtolower($subject));
            $this->assertStringNotContainsString('token=', strtolower($subject));
            $this->assertStringContainsString(__('terms.app_name'), $html);
            $this->assertStringContainsString(__('mail.brand.footer.help'), $html);
            $this->assertStringContainsString(
                url('/logos/myapes-header-light-600x128.png'),
                $html,
            );
        }
    }

    public function test_passkey_registered_event_sends_security_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'password']);
        $passkey = $user->passkeys()->make([
            'name' => 'Matrix Event Key',
            'credential_id' => Base64UrlSafe::encodeUnpadded('matrix-event-cred'),
            'credential' => ['type' => 'public-key'],
        ]);
        $passkey->id = 234;

        Event::dispatch(new PasskeyRegistered($user, $passkey));

        Notification::assertSentTo($user, PasskeyAddedNotification::class);
    }
}
