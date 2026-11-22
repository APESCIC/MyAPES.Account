<?php

namespace App\Core\Push;

/**
 * Stable push type keys for the epic #791 event-to-role table (#796).
 */
final class PushNotificationTypeKeys
{
    public const TICKET_CREATED = 'ticket.created';

    public const TICKET_CLIENT_REPLY = 'ticket.client_reply';

    public const TICKET_STAFF_REPLY = 'ticket.staff_reply';

    public const TICKET_ASSIGNED = 'ticket.assigned';

    public const TICKET_INTERNAL_NOTE = 'ticket.internal_note';

    public const TICKET_STATUS_CHANGED = 'ticket.status_changed';

    public const CASE_UPDATE_SHARED = 'case.update_shared';

    public const CONSULTATION_UPDATE = 'consultation.update';

    public const RECRUITMENT_APPLICATION_UPDATE = 'recruitment.application_update';

    public const ACCOUNT_SECURITY_CHANGE = 'account.security_change';

    public const TICKET_EMAIL_REPLY_FAILED = 'ticket.email_reply_failed';

    public const MAIL_IMPORT_ATTENTION = 'mail.import_attention';

    public const STAFF_MESSAGE = 'staff.message';

    /**
     * @return list<string>
     */
    public static function catalogueKeys(): array
    {
        return [
            self::TICKET_CREATED,
            self::TICKET_CLIENT_REPLY,
            self::TICKET_STAFF_REPLY,
            self::TICKET_ASSIGNED,
            self::TICKET_INTERNAL_NOTE,
            self::TICKET_STATUS_CHANGED,
            self::CASE_UPDATE_SHARED,
            self::CONSULTATION_UPDATE,
            self::RECRUITMENT_APPLICATION_UPDATE,
            self::ACCOUNT_SECURITY_CHANGE,
            self::TICKET_EMAIL_REPLY_FAILED,
            self::MAIL_IMPORT_ATTENTION,
            self::STAFF_MESSAGE,
        ];
    }

    public static function typeLabel(string $typeKey): string
    {
        $labelKey = self::typeLabelKey($typeKey);

        return $labelKey !== null ? (string) __($labelKey) : $typeKey;
    }

    public static function statsRoleLabel(string $role): string
    {
        $labelKey = match ($role) {
            'staff' => 'admin.settings.push_notifications.stats.roles.staff',
            'volunteer' => 'admin.settings.push_notifications.stats.roles.volunteer',
            'student' => 'admin.settings.push_notifications.stats.roles.student',
            'service_user' => 'admin.settings.push_notifications.stats.roles.service_user',
            'admin' => 'admin.settings.push_notifications.stats.roles.admin',
            default => null,
        };

        return $labelKey !== null ? (string) __($labelKey) : $role;
    }

    public static function payloadBody(string $typeKey, string $reference = '', ?string $serviceLabel = null): string
    {
        $payloadKey = self::payloadBodyKey($typeKey);

        if ($payloadKey === null) {
            return $typeKey;
        }

        if ($reference === '') {
            return (string) __($payloadKey);
        }

        $line = (string) __($payloadKey, ['reference' => $reference]);

        if ($serviceLabel !== null && $serviceLabel !== '') {
            $line .= (string) __('push.payload.service_scope', ['service' => $serviceLabel]);
        }

        return $line;
    }

    public static function typeLabelKey(string $typeKey): ?string
    {
        return match ($typeKey) {
            self::TICKET_CREATED => 'push.types.ticket_created',
            self::TICKET_CLIENT_REPLY => 'push.types.ticket_client_reply',
            self::TICKET_STAFF_REPLY => 'push.types.ticket_staff_reply',
            self::TICKET_ASSIGNED => 'push.types.ticket_assigned',
            self::TICKET_INTERNAL_NOTE => 'push.types.ticket_internal_note',
            self::TICKET_STATUS_CHANGED => 'push.types.ticket_status_changed',
            self::CASE_UPDATE_SHARED => 'push.types.case_update_shared',
            self::CONSULTATION_UPDATE => 'push.types.consultation_update',
            self::RECRUITMENT_APPLICATION_UPDATE => 'push.types.recruitment_application_update',
            self::ACCOUNT_SECURITY_CHANGE => 'push.types.account_security_change',
            self::TICKET_EMAIL_REPLY_FAILED => 'push.types.ticket_email_reply_failed',
            self::MAIL_IMPORT_ATTENTION => 'push.types.mail_import_attention',
            self::STAFF_MESSAGE => 'push.types.staff_message',
            default => null,
        };
    }

    private static function payloadBodyKey(string $typeKey): ?string
    {
        return match ($typeKey) {
            self::TICKET_CREATED => 'push.payload.ticket_created',
            self::TICKET_CLIENT_REPLY => 'push.payload.ticket_client_reply',
            self::TICKET_STAFF_REPLY => 'push.payload.ticket_staff_reply',
            self::TICKET_ASSIGNED => 'push.payload.ticket_assigned',
            self::TICKET_INTERNAL_NOTE => 'push.payload.ticket_internal_note',
            self::TICKET_STATUS_CHANGED => 'push.payload.ticket_status_changed',
            self::CASE_UPDATE_SHARED => 'push.payload.case_update_shared',
            self::CONSULTATION_UPDATE => 'push.payload.consultation_update',
            self::RECRUITMENT_APPLICATION_UPDATE => 'push.payload.recruitment_application_update',
            self::ACCOUNT_SECURITY_CHANGE => 'push.payload.account_security_change',
            self::TICKET_EMAIL_REPLY_FAILED => 'push.payload.ticket_email_reply_failed',
            self::MAIL_IMPORT_ATTENTION => 'push.payload.mail_import_attention',
            self::STAFF_MESSAGE => 'push.payload.staff_message',
            default => null,
        };
    }
}