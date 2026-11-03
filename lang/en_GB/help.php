<?php

/**
 * Help guides (#464): the public guide lives in public.php; this file holds
 * the staff guide and the links between the two.
 */
return [
    'nav' => [
        'staff_guide' => 'Staff guide',
    ],

    'keywords' => [
        'staff_guide' => ['help', 'guide', 'staff', 'volunteer', 'student', 'how to'],
    ],

    'public_guide' => [
        'staff_link_intro' => 'Staff, volunteers and students:',
        'staff_link' => 'read the staff guide',
    ],

    'staff' => [
        'title' => 'Staff guide',
        'eyebrow' => 'Staff guide',
        'intro' => 'How the Staff and Admin areas fit together, for staff, volunteers, students and administrators.',
        'public_guide_link' => 'Public guide',
        'areas' => [
            'heading' => 'The three areas',
            'public' => 'Public: pages anyone can open without signing in, such as Help, the change log and the legal notices.',
            'staff' => 'Staff: your dashboard, profile and the services and plugins your group can use.',
            'admin' => 'Admin: people, access, modules, plugins, emails and maintenance. Only administrators see it, and they switch with the Staff | Admin control at the top of the menu.',
        ],
        'dashboard' => [
            'heading' => 'Your dashboard',
            'body' => 'After you sign in you land on the staff dashboard. It lists the open items that need you first, then a summary for each service you can use.',
        ],
        'services' => [
            'heading' => 'Services and plugins',
            'body' => 'Each service (APES CIC, APES Shelter and Rescue, APES Pet Care Clinic) has its own home in the menu. The plugins under it, such as Tickets, Cases, Pet Profiles, Consultations and Recruitment, depend on the access your administrator gave your group.',
            'missing' => 'If a plugin you need is missing, ask an administrator to check the access matrix for your group.',
        ],
        'search' => [
            'heading' => 'Search and quick actions',
            'body' => 'Press Ctrl K (or use Search at the top of the page) to jump to any page you can open, or to create a ticket, case or record.',
        ],
        'account' => [
            'heading' => 'Your account',
            'body' => 'Staff accounts sign in through APES Cloudron. Use Profile to check your details, two-factor authentication and passkeys.',
        ],
        'support' => [
            'heading' => 'When something goes wrong',
            'body' => 'If the software looks broken, open an issue from the Display and help menu. For anything about a person or a record, follow your service procedure first.',
        ],
    ],
];
