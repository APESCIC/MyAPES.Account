<?php

/**
 * Workspace shell chrome (#382): rail, panel switch, user dock, page header
 * and command palette.
 */
return [
    'app_name' => 'MyAPES Account',
    'panel_subtitle' => ':panel panel',

    'panels' => [
        'staff' => 'Staff',
        'account' => 'Account',
        'admin' => 'Admin',
        'public' => 'Public',
    ],

    'groups' => [
        'overview' => 'Overview',
        'support' => 'Support',
        'services' => 'Services',
        'people' => 'People',
        'modules' => 'Modules',
        'system' => 'System',
        'explore' => 'Explore',
        'account' => 'Account',
    ],

    'nav' => [
        'label' => 'Workspace navigation',
        'panel_switch' => 'Panel',
        'service_home' => 'Service home',
        'change_log' => 'Change log',
        'brand_home' => 'MyAPES Account home',
    ],

    'mobile' => [
        'open' => 'Open workspace navigation',
        'close' => 'Close workspace navigation',
        'search' => 'Search pages and actions',
    ],

    'header' => [
        'breadcrumb' => 'Breadcrumb',
        'search' => 'Search',
        'view_site' => 'View site',
        'view_site_hint' => 'Opens the MyAPES Account home page',
    ],

    'dock' => [
        'account' => 'Account',
        'sign_out' => 'Sign out',
        'log_in' => 'Log in',
        'register' => 'Register',
        'tools' => 'Display and help',
        'qa_roles' => 'QA role',
        'qa_current' => 'Current QA role: :role',
        'github' => 'GitHub',
        'opens_in_new_tab' => 'opens in a new tab',
        'theme_dark' => 'Switch to dark theme',
        'theme_light' => 'Switch to light theme',
    ],

    'palette' => [
        'title' => 'Search and run commands',
        'placeholder' => 'Jump to a page or run an action…',
        'results' => 'Results',
        'empty' => 'No matching pages or actions.',
        'quick_actions' => 'Quick actions',
        'settings_section' => 'Settings',
        'close_hint' => 'Esc',
    ],

    'quick' => [
        'switch_panel' => 'Switch to :panel panel',
        'your_account' => 'Your account',
        'create' => [
            'default' => 'Create :item',
            'tickets' => 'Create ticket',
            'cases' => 'Create case',
            'pet-profiles' => 'Add pet profile',
            'consultations' => 'Book consultation',
            'recruitment' => 'Create recruitment role',
        ],
    ],

    'keywords' => [
        'dashboard' => ['home', 'overview', 'summary', 'start'],
        'profile' => ['account', 'me', 'password', 'security', 'two-factor', 'passkeys'],
        'help' => ['support', 'guide', 'faq', 'questions'],
        'change_log' => ['releases', 'versions', 'what is new', 'updates'],
        'service_home' => ['service', 'hub', 'module'],
        'staff_plugin' => ['plugin'],
        'login' => ['sign in', 'log in'],
        'register' => ['sign up', 'create account', 'join'],
        'staff_login' => ['staff', 'employee', 'cloudron', 'sign in'],
        'switch_panel' => ['panel', 'workspace', 'switch'],
        'view_site' => ['home', 'public', 'site', 'landing'],
    ],
];
