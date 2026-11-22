<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\StaffMessage;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Notifications\Auth\EmailChangeCompletedNotification;
use App\Notifications\Auth\EmailChangeConfirmNotification;
use App\Notifications\Auth\EmailChangeStartedNotification;
use App\Notifications\Auth\PasskeyAddedNotification;
use App\Notifications\Auth\PasskeyRemovedNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\StaffAssistedSetPasswordNotification;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\EmailReplyNotPostedNotification;
use App\Notifications\InboundImportFailingNotification;
use App\Notifications\InboundImportRecoveredNotification;
use App\Notifications\PendingFirstLoginChaseNotification;
use App\Notifications\StaffClientRecordsArchivedNotification;
use App\Notifications\StaffMessageNotification;
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
            self::auth('set_password', 'set_password', StaffAssistedSetPasswordNotification::class,
                static fn (): StaffAssistedSetPasswordNotification => new StaffAssistedSetPasswordNotification('sample-preview-token')),
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
            new MailTemplate(
                key: 'accounts.staff_client_records_archived',
                notification: StaffClientRecordsArchivedNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.accounts.staff_client_records_archived.name',
                descriptionKey: 'mail.templates.accounts.staff_client_records_archived.description',
                sample: static fn (): StaffClientRecordsArchivedNotification => new StaffClientRecordsArchivedNotification(['Tickets' => 2, 'Pet profiles' => 1]),
                subjectKey: 'mail.staff_client_records_archived.subject',
                introKey: 'mail.staff_client_records_archived.line_archived',
            ),
            new MailTemplate(
                key: 'inbound.import_failing',
                notification: InboundImportFailingNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.inbound.import_failing.name',
                descriptionKey: 'mail.templates.inbound.import_failing.description',
                sample: static fn (): InboundImportFailingNotification => new InboundImportFailingNotification(FailureType::LoginFailed, 3),
                subjectKey: 'mail.inbound_import_failing.subject',
                introKey: 'mail.inbound_import_failing.line_failing',
            ),
            new MailTemplate(
                key: 'inbound.import_recovered',
                notification: InboundImportRecoveredNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.inbound.import_recovered.name',
                descriptionKey: 'mail.templates.inbound.import_recovered.description',
                sample: static fn (): InboundImportRecoveredNotification => new InboundImportRecoveredNotification,
                subjectKey: 'mail.inbound_import_recovered.subject',
                introKey: 'mail.inbound_import_recovered.line_working',
            ),
            new MailTemplate(
                key: 'inbound.reply_not_posted',
                notification: EmailReplyNotPostedNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.inbound.reply_not_posted.name',
                descriptionKey: 'mail.templates.inbound.reply_not_posted.description',
                sample: static fn (): EmailReplyNotPostedNotification => new EmailReplyNotPostedNotification('Ticket #1024', url('/apes-cic/tickets/1024')),
                subjectKey: 'mail.reply_not_posted.subject',
                introKey: 'mail.reply_not_posted.line_closed',
            ),
            new MailTemplate(
                key: 'staff.staff_message',
                notification: StaffMessageNotification::class,
                category: MailCategory::Service,
                labelKey: 'mail.templates.staff.staff_message.name',
                descriptionKey: 'mail.templates.staff.staff_message.description',
                sample: static function (User $recipient): StaffMessageNotification {
                    $sender = new User(['name' => 'Sample Staff Member', 'email' => 'staff@example.com']);
                    $message = new StaffMessage([
                        'category' => StaffMessage::CATEGORY_SERVICE,
                        'subject' => __('mail.staff_message.sample_subject'),
                        'body_html' => '<p>'.e(__('mail.staff_message.sample_body')).'</p>',
                    ]);

                    return new StaffMessageNotification($message, $sender);
                },
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
