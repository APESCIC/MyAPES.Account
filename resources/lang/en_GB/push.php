<?php

return [
    'types' => [
        'ticket_created' => 'New ticket in my division',
        'ticket_client_reply' => 'Client reply on a ticket',
        'ticket_staff_reply' => 'Staff reply on my ticket',
        'ticket_assigned' => 'Ticket assigned to me',
        'ticket_internal_note' => 'Internal note on my assigned ticket',
        'ticket_status_changed' => 'Ticket resolved or closed',
        'case_update_shared' => 'Case update shared with the client',
        'consultation_update' => 'Consultation update',
        'recruitment_application_update' => 'Recruitment application update',
        'account_security_change' => 'Account security change',
        'ticket_email_reply_failed' => 'Reply by email not posted',
        'mail_import_attention' => 'Email import failing or recovered',
        'staff_message' => 'Message from APES staff',
    ],

    /**
     * Lock-screen bodies: type label plus record reference and optional service name (#799).
     */
    'payload' => [
        'service_scope' => ' (:service)',
        'ticket_created' => 'New ticket #:reference',
        'ticket_client_reply' => 'Client reply on ticket #:reference',
        'ticket_staff_reply' => 'Staff reply on ticket #:reference',
        'ticket_assigned' => 'Ticket assigned #:reference',
        'ticket_internal_note' => 'Internal note on ticket #:reference',
        'ticket_status_changed' => 'Ticket update #:reference',
        'case_update_shared' => 'Case update #:reference',
        'consultation_update' => 'Consultation update #:reference',
        'recruitment_application_update' => 'Recruitment application #:reference',
        'account_security_change' => 'Account security change',
        'ticket_email_reply_failed' => 'Reply not posted on ticket #:reference',
        'mail_import_attention' => 'Email import attention #:reference',
        'staff_message' => 'Message from APES staff #:reference',
    ],
];