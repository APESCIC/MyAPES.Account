<?php

return [
    'nav' => 'Messages',
    'title' => 'Staff messages',
    'intro' => 'Send Service or Optional email to account holders. Drafts are saved until you confirm sending.',
    'create' => 'Compose message',
    'edit' => 'Edit draft',
    'preview' => 'Preview and send',
    'history' => 'Message history',

    'fields' => [
        'category' => 'Category',
        'category_service' => 'Service',
        'category_service_help' => 'Operational updates that may reach everyone with a valid email address, regardless of Optional contact preferences.',
        'category_optional' => 'Optional',
        'category_optional_help' => 'Non-essential messages. Only people who turned on Email contact preferences receive these, with an unsubscribe link.',
        'subject' => 'Subject',
        'body' => 'Message body',
        'recipients' => 'Recipients',
        'filter_mode' => 'Send to',
        'mode_all' => 'Everyone with an email address',
        'mode_group' => 'Account group',
        'mode_module' => 'People who use a module',
        'mode_users' => 'Named people',
        'confirm_count' => 'Type the recipient count to confirm',
    ],

    'filters' => [
        'group_public' => 'Public accounts',
        'group_student' => 'Students',
        'group_volunteer' => 'Volunteers',
        'group_staff' => 'Staff',
        'group_admin' => 'Administrators',
    ],

    'status' => [
        'draft' => 'Draft',
        'preview' => 'Ready to send',
        'queued' => 'Queued',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],

    'counts' => [
        'will_receive' => ':count will receive this email',
        'opted_out' => ':count opted out of Optional email and will not receive it',
    ],

    'confirm' => [
        'heading' => 'Confirm sending',
        'large_send' => 'You are about to email more than 100 people or everyone on the system. Type the recipient count exactly to continue.',
        'send' => 'Queue for sending',
    ],

    'actions' => [
        'save_draft' => 'Save draft',
        'continue_preview' => 'Continue to preview',
        'back_edit' => 'Back to edit',
    ],

    'show' => [
        'sent_by' => 'Sent by :name',
        'stats' => ':sent sent · :failed failed · :opted opted out',
    ],

    'flash' => [
        'draft_saved' => 'Draft saved.',
        'queued' => 'Message queued for sending.',
    ],

    'errors' => [
        'no_recipients' => 'No recipients match this filter.',
        'queue_failed' => 'Could not queue this message. Try confirming again.',
    ],

    'unsubscribe' => [
        'title' => 'Unsubscribed',
        'heading' => 'You have opted out of Optional email',
        'body' => 'You will no longer receive Optional messages from MyAPES Account. Service and Security email may still be sent when needed.',
        'confirm_title' => 'Confirm unsubscribe',
        'confirm_heading' => 'Stop Optional email?',
        'confirm_body' => 'Confirm to stop receiving Optional messages from MyAPES Account. Service and Security email may still be sent when needed.',
        'confirm_action' => 'Unsubscribe from Optional email',
    ],
];
