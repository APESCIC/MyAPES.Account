<?php

return [
    'brand' => [
        'greeting' => 'Hello :name,',
        'custom_body_action' => 'Open MyAPES Account',
        'greeting_fallback' => 'there',
        'action_fallback' => 'If the button does not work, open this address in your browser: :url',
        'footer' => [
            'copyright' => '© :year :app. All rights reserved.',
            'help' => 'Need help? Visit the Help page',
            'no_reply' => 'Replies to this email are not read.',
            'log_in_to_reply' => 'Log in to reply',
            'salutation' => '— MyAPES Account',
        ],
    ],

    'categories' => [
        'security' => 'Security',
        'service' => 'Service',
        'optional' => 'Optional',
    ],

    'templates' => [
        'auth' => [
            'verify_email' => [
                'name' => 'Verify email address',
                'description' => 'Sent when a Public Login account registers or asks for a new verification link.',
            ],
            'reset_password' => [
                'name' => 'Reset password',
                'description' => 'Sent when someone asks to reset a Public Login password.',
            ],
            'password_changed' => [
                'name' => 'Password changed',
                'description' => 'Confirms a Public Login password change.',
            ],
            'two_factor_enabled' => [
                'name' => 'Authenticator app enabled',
                'description' => 'Security notice when an authenticator app is turned on.',
            ],
            'two_factor_disabled' => [
                'name' => 'Authenticator app disabled',
                'description' => 'Security notice when an authenticator app is turned off.',
            ],
            'passkey_added' => [
                'name' => 'Passkey added',
                'description' => 'Security notice when a passkey is added.',
            ],
            'passkey_removed' => [
                'name' => 'Passkey removed',
                'description' => 'Security notice when a passkey is removed.',
            ],
            'email_change_started' => [
                'name' => 'Email change started',
                'description' => 'Sent to the current address when an email change begins.',
            ],
            'email_change_confirm' => [
                'name' => 'Confirm new email',
                'description' => 'Sent to the new address with the confirmation link.',
            ],
            'email_change_completed' => [
                'name' => 'Email change completed',
                'description' => 'Sent to the old and new addresses after an email change.',
            ],
        ],
        'accounts' => [
            'pending_first_login_chase' => [
                'name' => 'Staff Login reminder',
                'description' => 'Sent by an administrator to staff who have not completed their first Staff Login.',
            ],
            'staff_client_records_archived' => [
                'name' => 'Staff client records archived',
                'description' => 'Sent to a staff, volunteer, student or admin account after its personal client records were archived.',
            ],
        ],
        'inbound' => [
            'import_failing' => [
                'name' => 'Email import stopped',
                'description' => 'Sent once to administrators when MyAPES Account cannot import replies from the reply mailbox after 3 tries in a row or 15 minutes.',
            ],
            'import_recovered' => [
                'name' => 'Email import working again',
                'description' => 'Sent once to administrators when email import works again after it stopped.',
            ],
            'reply_not_posted' => [
                'name' => 'Email reply not added',
                'description' => 'Sent, at most once an hour, when an account holder replies by email to a closed ticket or case, or with a reply that cannot be matched to one.',
            ],
        ],
    ],

    'staff_client_records_archived' => [
        'subject' => 'Your personal MyAPES records have moved out of your staff account',
        'greeting' => 'Hello :name,',
        'line_archived' => 'Staff, volunteer, student and admin accounts can no longer hold personal client records, so we have archived these records on your staff account:',
        'line_count' => ':label: :count',
        'line_register' => 'To use APES services yourself, register a separate public account with a personal email address.',
        'line_recoverable' => 'Nothing has been deleted. If you need a record back, contact an APES administrator.',
        'action' => 'Register a public account',
    ],

    'pending_first_login' => [
        'subject' => 'Complete your MyAPES Staff Login',
        'greeting' => 'Hello :name,',
        'line_request' => 'An APES administrator asked you to complete your first Staff Login so your Cloudron directory account can link to MyAPES Account.',
        'line_cloudron' => 'Use Staff Login and continue with APES Cloudron. Your password and passkeys stay on Cloudron — this message is not a public password reset.',
        'action' => 'Open Staff Login',
    ],

    'ticket_updated' => [
        'subject' => ':service ticket #:id :event',
        'line_body' => 'Ticket #:id (:subject) was :event by :actor.',
        'line_status' => 'Status: :status',
        'line_priority' => 'Priority: :priority',
        'action' => 'Log in to reply',
    ],

    'case_updated' => [
        'subject' => 'APES CIC case #:id :event',
        'line_body' => 'Case #:id was :event by :actor.',
        'line_status' => 'Status: :status',
        'line_priority' => 'Priority: :priority',
        'action' => 'Log in to reply',
    ],

    'shelter_case_updated' => [
        'subject' => 'APES Shelter case #:id :event',
        'line_body' => 'Case #:id (:title) was :event by :actor.',
        'line_case_type' => 'Case type: :type',
        'line_status' => 'Status: :status',
        'action' => 'Log in to reply',
    ],

    'reply_by_email' => [
        'marker' => '—— Reply above this line ——',
        'line' => 'Reply to this email, or log in to reply.',
        'footer' => 'Reply to this email, or:',
        'badge' => 'by email',
        'attachments_only' => 'Sent attachments by email.',
    ],

    'inbound_import_failing' => [
        'subject' => 'MyAPES Account cannot import email replies',
        'greeting' => 'Hello :name,',
        'line_failing' => 'MyAPES Account has not been able to check the reply mailbox :count times in a row, so client replies by email are not being added to tickets and cases.',
        'line_reason' => 'Reason: :reason',
        'line_try' => 'What to try: :hint',
        'line_safe' => 'No email is lost: it stays in the mailbox and is imported as soon as the connection works again. You will get one more email when it does.',
        'action' => 'Open Email import',
    ],

    'inbound_import_recovered' => [
        'subject' => 'MyAPES Account is importing email replies again',
        'greeting' => 'Hello :name,',
        'line_working' => 'MyAPES Account can check the reply mailbox again, so client replies by email are being added to tickets and cases.',
        'line_caught_up' => 'Any replies that waited in the mailbox are being imported now.',
        'action' => 'Open Email import',
    ],

    'reply_not_posted' => [
        'subject' => 'Your email reply to :reference was not added',
        'line_closed' => ':reference is closed, so your email reply was not added.',
        'line_unmatched' => 'We could not tell which ticket or case your email reply was for, so it was not added yet. Our staff will check it.',
        'unmatched_reference' => 'a MyAPES Account email',
        'line_next' => 'If you still need help, log in to see the latest, or open a new request.',
        'action' => 'Log in to view',
    ],

    'attachments' => [
        'line_new' => '1 new attachment – log in to view.|:count new attachments – log in to view.',
    ],

    'attachment_quarantined' => [
        'subject' => 'A file you uploaded was blocked',
        'line_body' => 'Our virus check found a problem with :name, so it has been blocked and nobody can download it.',
        'line_next' => 'Check the file on your device with up-to-date antivirus software, then upload a clean copy if you still need to share it.',
        'action' => 'Log in to view',
    ],

    'consultation_updated' => [
        'subject' => 'APES Pet Care Clinic consultation #:id :event',
        'line_body' => 'Consultation #:id (:subject) was :event by :actor.',
        'line_status' => 'Status: :status',
        'action' => 'Open consultation',
    ],

    'auth' => [
        'verify' => [
            'subject' => 'Verify your MyAPES Account email',
            'greeting' => 'Hello :name,',
            'intro' => 'Please confirm this email address for your MyAPES Account. Tap the button below to finish verification.',
            'action' => 'Verify email address',
            'outro' => 'If you did not create a MyAPES Account, you can ignore this message.',
            'subcopy' => 'If the button does not work, open this address in your browser: :url',
        ],
        'reset' => [
            'subject' => 'Reset your MyAPES Account password',
            'greeting' => 'Hello :name,',
            'intro' => 'We received a request to reset the password for your MyAPES Account. Tap the button below to choose a new password.',
            'action' => 'Reset password',
            'expiry' => 'This link expires in :count minutes.',
            'outro' => 'If you did not ask to reset your password, you can ignore this message. Your password will stay the same.',
            'subcopy' => 'If the button does not work, open this address in your browser: :url',
        ],
        'password_changed' => [
            'subject' => 'Your MyAPES Account password was changed',
            'greeting' => 'Hello :name,',
            'intro' => 'Your MyAPES Account password was changed successfully. If you made this change, no further action is needed.',
            'action' => 'Open Public Login',
            'outro' => 'If you did not change your password, contact APES support straight away and reset your password from Public Login.',
        ],
        'two_factor_enabled' => [
            'subject' => 'Authenticator app enabled on MyAPES Account',
            'greeting' => 'Hello :name,',
            'intro' => 'An authenticator app (one-time codes) was enabled on your MyAPES Account.',
            'action' => 'Open profile',
            'outro' => 'If you did not enable this, change your password and review your security settings.',
        ],
        'two_factor_disabled' => [
            'subject' => 'Authenticator app disabled on MyAPES Account',
            'greeting' => 'Hello :name,',
            'intro' => 'Authenticator app sign-in was disabled on your MyAPES Account.',
            'action' => 'Open profile',
            'outro' => 'If you did not disable this, change your password and contact APES support.',
        ],
        'passkey_added' => [
            'subject' => 'Passkey added to MyAPES Account',
            'greeting' => 'Hello :name,',
            'intro' => 'A new passkey was added to your MyAPES Account.',
            'action' => 'Open profile',
            'outro' => 'If you did not add a passkey, change your password and review your security settings.',
        ],
        'passkey_removed' => [
            'subject' => 'Passkey removed from MyAPES Account',
            'greeting' => 'Hello :name,',
            'intro' => 'A passkey was removed from your MyAPES Account.',
            'action' => 'Open profile',
            'outro' => 'If you did not remove a passkey, change your password and contact APES support.',
        ],
        'email_change_started' => [
            'subject' => 'Confirm your new MyAPES Account email',
            'greeting' => 'Hello :name,',
            'intro' => 'A request was made to change the email address on your MyAPES Account. Confirm the new address using the link we sent there, or cancel from your profile.',
            'action' => 'Open profile',
            'outro' => 'If you did not start an email change, secure your account and contact APES support.',
        ],
        'email_change_confirm' => [
            'subject' => 'Confirm your new MyAPES Account email',
            'greeting' => 'Hello :name,',
            'intro' => 'Confirm :email as the new address for your MyAPES Account. Your current email stays active until you use this link.',
            'action' => 'Confirm new email',
            'outro' => 'If you did not request this change, ignore this message. The link expires soon.',
        ],
        'email_change_completed' => [
            'subject' => 'Your MyAPES Account email was updated',
            'greeting' => 'Hello :name,',
            'intro' => 'The email address on your MyAPES Account was updated successfully.',
            'action' => 'Open profile',
            'outro' => 'If you did not complete this change, contact APES support straight away.',
        ],
    ],
];
