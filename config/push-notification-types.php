<?php

use App\Core\Push\PushNotificationTypeKeys;

/**
 * Event-to-role defaults from epic #791. Keys must stay stable for preferences.
 */
return [
    'types' => [
        PushNotificationTypeKeys::TICKET_CREATED => [
            'label' => 'push.types.ticket_created',
            'division_scoped' => true,
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'opt_in',
                'student' => 'opt_in',
                'service_user' => 'na',
                'admin' => 'opt_in',
            ],
        ],
        PushNotificationTypeKeys::TICKET_CLIENT_REPLY => [
            'label' => 'push.types.ticket_client_reply',
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'na',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::TICKET_STAFF_REPLY => [
            'label' => 'push.types.ticket_staff_reply',
            'roles' => [
                'staff' => 'na',
                'volunteer' => 'na',
                'student' => 'na',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::TICKET_ASSIGNED => [
            'label' => 'push.types.ticket_assigned',
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'na',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::TICKET_INTERNAL_NOTE => [
            'label' => 'push.types.ticket_internal_note',
            'roles' => [
                'staff' => 'opt_in',
                'volunteer' => 'opt_in',
                'student' => 'opt_in',
                'service_user' => 'never',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::TICKET_STATUS_CHANGED => [
            'label' => 'push.types.ticket_status_changed',
            'roles' => [
                'staff' => 'na',
                'volunteer' => 'na',
                'student' => 'na',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::CASE_UPDATE_SHARED => [
            'label' => 'push.types.case_update_shared',
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::CONSULTATION_UPDATE => [
            'label' => 'push.types.consultation_update',
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'na',
                'student' => 'na',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE => [
            'label' => 'push.types.recruitment_application_update',
            'roles' => [
                'staff' => 'opt_in',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => [
            'label' => 'push.types.account_security_change',
            'security_locked' => true,
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'on',
                'admin' => 'on',
            ],
        ],
        PushNotificationTypeKeys::TICKET_EMAIL_REPLY_FAILED => [
            'label' => 'push.types.ticket_email_reply_failed',
            'roles' => [
                'staff' => 'on',
                'volunteer' => 'on',
                'student' => 'on',
                'service_user' => 'on',
                'admin' => 'na',
            ],
        ],
        PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION => [
            'label' => 'push.types.mail_import_attention',
            'roles' => [
                'staff' => 'na',
                'volunteer' => 'na',
                'student' => 'na',
                'service_user' => 'na',
                'admin' => 'on',
            ],
        ],
        PushNotificationTypeKeys::STAFF_MESSAGE => [
            'label' => 'push.types.staff_message',
            'roles' => [
                'staff' => 'na',
                'volunteer' => 'opt_in',
                'student' => 'opt_in',
                'service_user' => 'opt_in',
                'admin' => 'na',
            ],
        ],
    ],
];