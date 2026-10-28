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
            self::auth('verify_email', VerifyEmailNotification::class, MailCategory::Security,
                static fn (): VerifyEmailNotification => new VerifyEmailNotification),
            self::auth('reset_password', ResetPasswordNotification::class, MailCategory::Security,
                static fn (): ResetPasswordNotification => new ResetPasswordNotification('sample-preview-token')),
            self::auth('password_changed', PasswordChangedNotification::class, MailCategory::Security,
                static fn (): PasswordChangedNotification => new PasswordChangedNotification),
            self::auth('two_factor_enabled', TwoFactorEnabledNotification::class, MailCategory::Security,
                static fn (): TwoFactorEnabledNotification => new TwoFactorEnabledNotification),
            self::auth('two_factor_disabled', TwoFactorDisabledNotification::class, MailCategory::Security,
                static fn (): TwoFactorDisabledNotification => new TwoFactorDisabledNotification),
            self::auth('passkey_added', PasskeyAddedNotification::class, MailCategory::Security,
                static fn (): PasskeyAddedNotification => new PasskeyAddedNotification),
            self::auth('passkey_removed', PasskeyRemovedNotification::class, MailCategory::Security,
                static fn (): PasskeyRemovedNotification => new PasskeyRemovedNotification),
            self::auth('email_change_started', EmailChangeStartedNotification::class, MailCategory::Security,
                static fn (): EmailChangeStartedNotification => new EmailChangeStartedNotification),
            self::auth('email_change_confirm', EmailChangeConfirmNotification::class, MailCategory::Security,
                static fn (User $recipient): EmailChangeConfirmNotification => new EmailChangeConfirmNotification(
                    recipientName: $recipient->name,
                    confirmUrl: url('/email/change/confirm/sample-preview'),
                    newEmail: 'new.address@example.com',
                )),
            self::auth('email_change_completed', EmailChangeCompletedNotification::class, MailCategory::Security,
                static fn (): EmailChangeCompletedNotification => new EmailChangeCompletedNotification),
            new MailTemplate(
                key: 'accounts.pending_first_login_chase',
                notification: PendingFirstLoginChaseNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.accounts.pending_first_login_chase.name',
                descriptionKey: 'mail.templates.accounts.pending_first_login_chase.description',
                sample: static fn (User $recipient): PendingFirstLoginChaseNotification => new PendingFirstLoginChaseNotification($recipient),
            ),
        ];
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    private static function auth(string $name, string $notification, MailCategory $category, Closure $sample): MailTemplate
    {
        return new MailTemplate(
            key: 'auth.'.$name,
            notification: $notification,
            category: $category,
            labelKey: "mail.templates.auth.{$name}.name",
            descriptionKey: "mail.templates.auth.{$name}.description",
            sample: $sample,
        );
    }
}
