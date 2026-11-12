<?php

namespace App\Core\Webhooks;

/**
 * The outgoing webhook event catalogue (#641). Payloads are thin: the record's
 * id, its organisation and the names of the fields that changed, never personal
 * data. Apps fetch details through /api/v1 with their own token.
 */
final class WebhookEvents
{
    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_DELETED = 'user.deleted';

    public const CONSENT_CHANGED = 'consent.changed';

    public const STAFF_UPDATED = 'staff.updated';

    public const PET_CREATED = 'pet.created';

    public const PET_UPDATED = 'pet.updated';

    public const TICKET_CREATED = 'ticket.created';

    public const TICKET_UPDATED = 'ticket.updated';

    public const CASE_CREATED = 'case.created';

    public const CASE_UPDATED = 'case.updated';

    /**
     * Event => the app it was agreed for (5 Nov 2026).
     *
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        return [
            self::USER_CREATED => 'newsroom',
            self::USER_UPDATED => 'newsroom',
            self::USER_DELETED => 'newsroom',
            self::CONSENT_CHANGED => 'newsroom',
            self::STAFF_UPDATED => 'newsroom',
            self::PET_CREATED => 'easm',
            self::PET_UPDATED => 'easm',
            self::TICKET_CREATED => 'easm',
            self::TICKET_UPDATED => 'easm',
            self::CASE_CREATED => 'easm',
            self::CASE_UPDATED => 'easm',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::catalogue());
    }
}
