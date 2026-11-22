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
}