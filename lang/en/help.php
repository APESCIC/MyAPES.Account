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

    'appearance' => [
        'heading' => 'Appearance and themes',
        'intro' => 'MyAPES Account has two visual styles — Glassy (the default soft glass look) and Jungle — each in light or dark colour mode. The same friendly critter illustrations appear in both styles.',
        'persist_signed_in' => 'When you are signed in, your choices are saved to your account and follow you on other devices.',
        'persist_guest' => 'When you are not signed in, your choices are remembered on this browser only.',
        'user_menu' => 'Open your avatar in the top app bar and use the Theme controls at the bottom of the menu.',
        'profile' => 'Signed in? Open Account and scroll to Appearance for the same controls with more space.',
        'guest_footer' => 'On pages with a site footer, guests can change theme from the Theme section in the footer.',
        'glassy' => 'Glassy uses frosted cards, teal links and soft gradient washes.',
        'jungle' => 'Jungle adds leafy corners and jungle colour while keeping the same layout.',
        'calm' => 'Calm mode hides decorative critters in the menu and footer and turns off Jungle leaf decorations for a quieter screen. Help tips on pages may still show a critter where it explains the page.',
    ],

    'account_settings' => [
        'heading' => 'Account settings',
        'intro' => 'Your own account is split into four pages with tabs at the top. Open Account from your avatar in the app bar, or choose Your account from Search (Ctrl K).',
        'screenshots' => 'Design captures named ui-review-profile-*.png in docs/design-qa/ show the old single profile screen. The live app now uses the four tabs below instead.',
        'account' => 'Account: sign-in email, username (public accounts), a summary of sign-in methods, and Appearance (skin, light or dark, calm mode).',
        'profile' => 'Profile: personal and contact details, photo, and staff workplace details for directory-backed staff.',
        'security' => 'Security: password, passkeys, authenticator app, telephone verification PIN, signed-in devices, and recent security activity.',
        'privacy' => 'Privacy: contact preferences, optional email, push and email notification types, cookies summary, download my data, and deletion requests.',
        'legacy' => 'Old /profile links and bookmarks redirect to the matching tab.',
    ],

    'staff' => [
        'title' => 'Staff guide',
        'eyebrow' => 'Staff guide',
        'intro' => 'How to use MyAPES Account day to day, for staff, volunteers and students.',
        'public_guide_link' => 'Public guide',
        'areas' => [
            'heading' => 'The areas you may see',
            'public' => 'Public: pages anyone can open without signing in, such as Help, the change log and the legal notices.',
            'staff' => 'Staff: your dashboard, profile and the services and tools your team can use.',
            'admin' => 'Admin: people, access, organisation settings, emails and maintenance. Only administrators see it, and they switch with the Staff | Admin control at the top of the menu.',
        ],
        'dashboard' => [
            'heading' => 'Your dashboard',
            'body' => 'After you sign in you land on the staff dashboard. It lists the open items that need you first, then a summary for each service you can use.',
        ],
        'services' => [
            'heading' => 'Services and tools',
            'body' => 'Each service (APES CIC, APES Shelter and Rescue, APES Pet Care Clinic) has its own home in the menu. The tools under it, such as Tickets, Cases, Pet Profiles, Consultations and Recruitment, depend on the access your administrator gave your team.',
            'missing' => 'If a tool you need is missing, ask an administrator to check what your team can use.',
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
        'clients' => [
            'heading' => 'Creating a public client account',
            'body' => 'Staff, volunteers and students with the Create public client accounts permission can open Clients and create a local public account for someone who phones in. This never creates a Staff Account.',
            'pin' => 'You must set a 4–8 digit phone verification PIN. It is shown once after you save — write it down for the caller, then it disappears. The PIN is stored hashed and is never emailed, logged or printed again.',
            'email' => 'Email is optional. If you enter an email and tick the set-password option, the person gets a one-time link that expires after 72 hours. That email never includes the PIN or a password.',
            'consent' => 'Tick staff-assisted consent to record that you created the account with the person\'s agreement. The audit log stores who created it and whether the email was sent, without the PIN or password.',
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
            'body' => 'Staff Accounts sign in through Staff Login. Open Account from your avatar for four tabs: Account (sign-in and appearance), Profile (workplace details), Security (password, passkeys, two-factor authentication, telephone PIN and devices), and Privacy (notifications, contact preferences and data requests).',
        ],
        'support' => [
            'heading' => 'When something goes wrong',
            'body' => 'If the software looks broken, use Report a problem from the Display and help menu. For anything about a person or a record, follow your service procedure first.',
        ],
    ],

    'admin' => [
        'title' => 'Admin guide',
        'eyebrow' => 'Admin guide',
        'intro' => 'Where to find the Admin area tools and what to check first. You only see the parts your access allows. Technical topics (plugins, modules, directory sync, OIDC, API, webhooks and the access matrix) live here rather than in the Staff guide.',
        'overview' => [
            'heading' => 'Admin overview',
            'body' => 'Overview shows account, service and plugin numbers at a glance. Switch to it with the Staff | Admin control at the top of the menu.',
            'alerts' => 'The Plugin alerts count only includes plugins that need action, such as one whose code is not in this release. Plugin notes list states that need nothing doing. Each row says why and links to the fix in Plugins.',
        ],
        'people' => [
            'heading' => 'People and access',
            'body' => 'Public Accounts and Staff Accounts list every account by type. Open an account to check its profile and roles.',
            'access' => 'Access holds groups, roles and permissions. Give access to a group rather than one person, so that everyone doing the same job sees the same things. The access matrix and directory / OIDC group mappings are managed here.',
        ],
        'modules' => [
            'heading' => 'Modules, plugins and versions',
            'body' => 'Modules are the services (APES CIC, APES Shelter and Rescue, APES Pet Care Clinic). Plugins add features to a module. Turning a plugin off hides it without deleting its records. API clients and webhooks are also managed from Admin.',
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
            'heading' => 'Email Templates',
            'body' => 'Email Templates lists every message the app sends. You can change the subject and body, use the merge fields shown on the page, preview the result and reset to the standard text at any time.',
            'replies' => 'System emails come from a no-reply address. Ticket and case emails include a Log in to reply link, so replies stay in the app.',
        ],
        'maintenance' => [
            'heading' => 'Maintenance',
            'body' => 'Maintenance mode blocks public accounts and ordinary staff and pauses queued jobs. Health checks, staff sign-in and the Maintenance page stay available, so you can end it again once you have checked the app.',
        ],
        'security' => [
            'heading' => 'Security settings',
            'body' => 'The app sends security headers and enforces a Content Security Policy, so the browser blocks scripts and frames from sites the app does not trust. Every blocked item is logged. Setting CSP_ENFORCE=false switches back to report-only while you investigate.',
            'editor' => 'The description editor needs its API key in the app environment. Without the key, description fields fall back to a plain text box.',
        ],
    ],
];
