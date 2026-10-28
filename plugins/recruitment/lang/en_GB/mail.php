<?php

return [
    'action' => 'View your application',

    'received' => [
        'subject' => 'We received your application for :role',
        'line_received' => 'Thank you for applying for :role with APES CIC. Your application has been received.',
        'line_next' => 'We will email you when there is a decision. You can check your application at any time.',
    ],

    'status' => [
        'shortlisted' => [
            'subject' => 'You have been shortlisted for :role',
            'line' => 'Good news: your application for :role has been shortlisted. The APES CIC team will be in touch about next steps.',
        ],
        'rejected' => [
            'subject' => 'An update on your application for :role',
            'line' => 'Thank you for applying for :role. After careful review, we will not be taking your application further this time.',
        ],
        'accepted' => [
            'subject' => 'Your application for :role was accepted',
            'line' => 'Congratulations: your application for :role has been accepted. The APES CIC team will contact you about what happens next.',
        ],
        'closed' => [
            'subject' => 'Your application for :role has closed',
            'line' => 'The recruitment for :role has closed, so your application is now closed. Thank you for your interest.',
        ],
    ],

    'templates' => [
        'application_received' => [
            'name' => 'Recruitment application received',
            'description' => 'Sent to the applicant when they submit a Recruitment application.',
        ],
        'application_status' => [
            'name' => 'Recruitment decision',
            'description' => 'Sent to the applicant when their application is shortlisted, rejected, accepted, or closed.',
        ],
    ],
];
