<?php

namespace Plugins\Tickets;

use App\Core\Push\PushNotificationTypeKeys;

/**
 * Ticket activity kinds mapped to push notification type keys (#797).
 */
final class TicketPushKind
{
    public const CREATED = 'created';

    public const CLIENT_REPLY = 'client_reply';

    public const STAFF_REPLY = 'staff_reply';

    public const ASSIGNED = 'assigned';

    public const INTERNAL_NOTE = 'internal_note';

    public const STATUS_CHANGED = 'status_changed';

    public static function typeKey(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }

        return match ($kind) {
            self::CREATED => PushNotificationTypeKeys::TICKET_CREATED,
            self::CLIENT_REPLY => PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
            self::STAFF_REPLY => PushNotificationTypeKeys::TICKET_STAFF_REPLY,
            self::ASSIGNED => PushNotificationTypeKeys::TICKET_ASSIGNED,
            self::INTERNAL_NOTE => PushNotificationTypeKeys::TICKET_INTERNAL_NOTE,
            self::STATUS_CHANGED => PushNotificationTypeKeys::TICKET_STATUS_CHANGED,
            default => null,
        };
    }
}