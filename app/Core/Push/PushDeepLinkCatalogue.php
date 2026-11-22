<?php

namespace App\Core\Push;

/**
 * Documents deep-link routes used in private push payloads (#799).
 *
 * Each route already runs behind `web`, `auth`, and the record policy or permission
 * gate for that area. Guests are redirected to sign-in; signed-in users without
 * access receive the standard 403 page.
 */
final class PushDeepLinkCatalogue
{
    /**
     * @return array<string, list<string>>
     */
    public static function routesByType(): array
    {
        $ticketRoutes = [
            'apes-cic.tickets.show',
            'shelter.tickets.show',
            'petcare.tickets.show',
        ];

        return [
            PushNotificationTypeKeys::TICKET_CREATED => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_STAFF_REPLY => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_ASSIGNED => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_INTERNAL_NOTE => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_STATUS_CHANGED => $ticketRoutes,
            PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED => $ticketRoutes,
            PushNotificationTypeKeys::CASE_UPDATE_SHARED => [
                'apes-cic.cases.show',
                'shelter.cases.show',
            ],
            PushNotificationTypeKeys::CONSULTATION_UPDATE => [
                'petcare.consultations.show',
            ],
            PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE => [
                'apes-cic.recruitment.applications.show',
                'recruitment.applications.show',
            ],
            PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => [
                'profile.edit',
            ],
            PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION => [
                'admin.emails.inbound.show',
            ],
            PushNotificationTypeKeys::STAFF_MESSAGE => [
                'dashboard',
            ],
        ];
    }
}