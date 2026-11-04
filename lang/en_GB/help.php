<?php

/**
 * Help guides (#464, #490): the public guide lives in public.php; this file
 * holds the staff and admin guides and the links between them.
 */
return [
    'nav' => [
        'staff_guide' => 'Staff guide',
        'admin_guide' => 'Admin guide',
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
        'pets' => [
            'heading' => 'Pet records',
            'body' => 'APES Shelter and Rescue and APES Pet Care Clinic share one set of pet records. A pet added in either service shows in both, can have a Pet Care consultation and can be linked to a Shelter case.',
            'access' => 'What you can see and change depends on the service you open the pet from, so your Shelter access applies in Shelter and your Pet Care access applies in Pet Care.',
        ],
        'callers' => [
            'heading' => 'Verifying a caller',
            'body' => 'When someone phones, open Callers from the menu, find their account and ask for the two digits of their phone verification PIN that the page shows. Never ask for the whole PIN.',
            'lock' => 'Three wrong answers lock verification for that account for 15 minutes. Every attempt is recorded.',
            'end' => 'Once the caller is verified you can update their details and add a call log. Press End call when you finish; the verification also ends on its own after 60 minutes.',
        ],
        'call_logs' => [
            'heading' => 'Call logs',
            'body' => 'Call logs are for staff only and never show to the person they are about. Keep them factual. They are deleted automatically after 24 months.',
        ],
        'editor' => [
            'heading' => 'Writing descriptions',
            'body' => 'Description fields use a text editor with headings, lists and links. Formatting that is not safe is removed when you save, so what you see afterwards is what everyone else sees.',
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

    'admin' => [
        'title' => 'Admin guide',
        'eyebrow' => 'Admin guide',
        'intro' => 'Where to find the Admin area tools and what to check first. You only see the parts your access allows.',
        'overview' => [
            'heading' => 'Admin overview',
            'body' => 'Overview shows account, service and plugin numbers at a glance. Switch to it with the Staff | Admin control at the top of the menu.',
            'alerts' => 'The Plugin alerts count only includes plugins that need action, such as one whose code is not in this release. Plugin notes list states that need nothing doing. Each row says why and links to the fix in Plugins.',
        ],
        'people' => [
            'heading' => 'People and access',
            'body' => 'Public users and Staff list every account by type. Open an account to check its profile and roles.',
            'access' => 'Access holds groups, roles and permissions. Give access to a group rather than one person, so that everyone doing the same job sees the same things.',
        ],
        'modules' => [
            'heading' => 'Modules, plugins and versions',
            'body' => 'Modules are the services (APES CIC, APES Shelter and Rescue, APES Pet Care Clinic). Plugins add features to a module. Turning a plugin off hides it without deleting its records.',
            'versions' => 'Versions shows the installed version of each module and plugin and its history of changes.',
        ],
        'lists' => [
            'heading' => 'Managed lists and ticket settings',
            'body' => 'Service areas and case categories are managed lists on each module. You can add, rename, reorder and remove items; removed items stay on existing records and can be restored.',
        ],
        'recruitment' => [
            'heading' => 'Recruitment forms',
            'body' => 'Each role in Recruitment has its own application questions. Add short or long answer questions on the role page and move them into the order applicants should see.',
        ],
        'emails' => [
            'heading' => 'Emails',
            'body' => 'Emails lists every message the app sends. You can change the subject and body, use the merge fields shown on the page, preview the result and reset to the standard text at any time.',
            'replies' => 'System emails come from a no-reply address. Ticket and case emails include a Log in to reply link, so replies stay in the app.',
        ],
        'maintenance' => [
            'heading' => 'Maintenance',
            'body' => 'Maintenance mode blocks public users and ordinary staff and pauses queued jobs. Health checks, staff sign-in and the Maintenance page stay available, so you can end it again once you have checked the app.',
        ],
        'security' => [
            'heading' => 'Security settings',
            'body' => 'The app sends security headers and enforces a Content Security Policy, so the browser blocks scripts and frames from sites the app does not trust. Every blocked item is logged. Setting CSP_ENFORCE=false switches back to report-only while you investigate.',
            'editor' => 'The description editor needs its API key in the app environment. Without the key, description fields fall back to a plain text box.',
        ],
    ],
];
