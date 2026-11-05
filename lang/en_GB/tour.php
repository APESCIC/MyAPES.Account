<?php

/**
 * Spike's guided tour (#489).
 */
return [
    'restart' => 'Restart the guided tour',
    'restart_intro' => 'Want a reminder of where things are?',
    'keywords' => ['tour', 'guide', 'walkthrough', 'help', 'spike', 'restart', 'show me around'],
    'buttons' => [
        'next' => 'Next',
        'previous' => 'Back',
        'done' => 'Finish',
        'progress' => '{{current}} of {{total}}',
        'close' => 'Skip the tour',
    ],
    'steps' => [
        'welcome' => [
            'title' => 'Hello, I am Spike',
            'public' => 'Let me show you around MyAPES Account. It only takes a minute, and you can skip at any time.',
            'staff' => 'Let me show you around the Staff area. It only takes a minute, and you can skip at any time.',
            'admin' => 'Let me show you around the Staff and Admin areas. It only takes a minute, and you can skip at any time.',
        ],
        'panel_switch' => [
            'title' => 'Staff and Admin',
            'body' => 'Switch between the Staff area and the Admin area here. Admin holds people, access, modules, plugins, emails and maintenance.',
        ],
        'menu' => [
            'title' => 'Your menu',
            'public' => 'The menu lists your dashboard, profile and each APES service you use. On a small screen, open it with the menu button.',
            'staff' => 'The menu lists your dashboard and every service and plugin your group can use. On a small screen, open it with the menu button.',
        ],
        'profile' => [
            'title' => 'Your profile',
            'body' => 'Keep your contact details up to date, choose your services and set your phone verification PIN here.',
        ],
        'bookings' => [
            'title' => 'Book a meeting',
            'body' => 'Book an appointment with APES, APES Shelter and Rescue or APES Pet Care Clinic from Bookings.',
        ],
        'callers' => [
            'title' => 'Callers',
            'body' => 'When someone phones, find them here and check two digits of their phone PIN before you change anything.',
        ],
        'search' => [
            'title' => 'Search and quick actions',
            'body' => 'Press Ctrl K, or use Search, to jump to any page or start a ticket, case or record.',
        ],
        'guides' => [
            'title' => 'Guides',
            'staff' => 'The Staff guide explains pets, callers, call logs and more.',
            'admin' => 'The Staff guide explains day-to-day work. The Admin guide is in the Support group of the Admin menu.',
        ],
        'account' => [
            'title' => 'Your account',
            'body' => 'Your name and role show here, with links to your account settings and Sign out.',
        ],
        'help' => [
            'title' => 'Help is always here',
            'body' => 'Help is linked at the bottom of every page. You can restart this tour from Help or with Ctrl K.',
        ],
    ],
];
