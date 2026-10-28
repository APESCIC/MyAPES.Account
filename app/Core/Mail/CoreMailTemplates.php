<?php

namespace App\Core\Mail;

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
use App\Notifications\PendingFirstLoginChaseNotification;
use Closure;
use Illuminate\Notifications\Notification;

final class CoreMailTemplates
{
    /**
     * @return list<MailTemplate>
     */
    public static function all(): array
    {
        return [
            self::auth('verify_email', 'verify', VerifyEmailNotification::class,
                static fn (): VerifyEmailNotification => new VerifyEmailNotification),
            self::auth('reset_password', 'reset', ResetPasswordNotification::class,
                static fn (): ResetPasswordNotification => new ResetPasswordNotification('sample-preview-token')),
            self::auth('password_changed', 'password_changed', PasswordChangedNotification::class,
                static fn (): PasswordChangedNotification => new PasswordChangedNotification),
            self::auth('two_factor_enabled', 'two_factor_enabled', TwoFactorEnabledNotification::class,
                static fn (): TwoFactorEnabledNotification => new TwoFactorEnabledNotification),
            self::auth('two_factor_disabled', 'two_factor_disabled', TwoFactorDisabledNotification::class,
                static fn (): TwoFactorDisabledNotification => new TwoFactorDisabledNotification),
            self::auth('passkey_added', 'passkey_added', PasskeyAddedNotification::class,
                static fn (): PasskeyAddedNotification => new PasskeyAddedNotification),
            self::auth('passkey_removed', 'passkey_removed', PasskeyRemovedNotification::class,
                static fn (): PasskeyRemovedNotification => new PasskeyRemovedNotification),
            self::auth('email_change_started', 'email_change_started', EmailChangeStartedNotification::class,
                static fn (): EmailChangeStartedNotification => new EmailChangeStartedNotification),
            self::auth('email_change_confirm', 'email_change_confirm', EmailChangeConfirmNotification::class,
                static fn (User $recipient): EmailChangeConfirmNotification => new EmailChangeConfirmNotification(
                    recipientName: $recipient->name,
                    confirmUrl: url('/email/change/confirm/sample-preview'),
                    newEmail: 'new.address@example.com',
                )),
            self::auth('email_change_completed', 'email_change_completed', EmailChangeCompletedNotification::class,
                static fn (): EmailChangeCompletedNotification => new EmailChangeCompletedNotification),
            new MailTemplate(
                key: 'accounts.pending_first_login_chase',
                notification: PendingFirstLoginChaseNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.accounts.pending_first_login_chase.name',
                descriptionKey: 'mail.templates.accounts.pending_first_login_chase.description',
                sample: static fn (User $recipient): PendingFirstLoginChaseNotification => new PendingFirstLoginChaseNotification($recipient),
                subjectKey: 'mail.pending_first_login.subject',
                introKey: 'mail.pending_first_login.line_request',
            ),
        ];
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    private static function auth(string $name, string $langGroup, string $notification, Closure $sample): MailTemplate
    {
        return new MailTemplate(
            key: 'auth.'.$name,
            notification: $notification,
            category: MailCategory::Security,
            labelKey: "mail.templates.auth.{$name}.name",
            descriptionKey: "mail.templates.auth.{$name}.description",
            sample: $sample,
            subjectKey: "mail.auth.{$langGroup}.subject",
            introKey: "mail.auth.{$langGroup}.intro",
        );
    }
}
