<?php

/**
 * Machine-readable glossary metadata for CI synonym lint (#276) and docs/glossary.md.
 *
 * Display labels only — do not treat stored permission strings or live URLs as violations.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Canonical term keys
    |--------------------------------------------------------------------------
    |
    | Maps stable ids to lang keys under lang/en_GB/terms.php.
    |
    */
    'canonical' => [
        'app_name' => 'terms.app_name',
        'core' => 'terms.core',
        'module' => 'terms.module',
        'plugin' => 'terms.plugin',
        'admin' => 'terms.admin',
        'super_admin' => 'terms.super_admin',
        'public_user' => 'terms.public_user',
        'staff' => 'terms.staff',
        'job_role' => 'terms.job_role',
        'recruitment_role' => 'terms.recruitment_role',
        'open_roles' => 'terms.open_roles',
        'my_applications' => 'terms.my_applications',
        'apes_cic' => 'terms.apes_cic',
        'pet_care_clinic' => 'terms.pet_care_clinic',
        'shelter_and_rescue' => 'terms.shelter_and_rescue',
        'tickets' => 'terms.tickets',
        'cases' => 'terms.cases',
        'recruitment' => 'terms.recruitment',
        'consultations' => 'terms.consultations',
        'pet_profiles' => 'terms.pet_profiles',
        'status' => 'terms.status',
        'priority' => 'terms.priority',
        'case_type' => 'terms.case_type',
        'recruitment_category' => 'terms.recruitment_category',
        'sex' => 'terms.sex',
        'neutering_status' => 'terms.neutering_status',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deprecated synonyms
    |--------------------------------------------------------------------------
    |
    | Patterns CI may grep in Blade and lang values after extraction waves.
    | context: where the synonym is discouraged (nav / public_ui / admin_ui / docs_ok).
    |
    */
    'deprecated_synonyms' => [
        [
            'pattern' => 'MyAPES Core',
            'canonical' => 'MyAPES Account',
            'contexts' => ['nav', 'public_ui', 'admin_ui', 'docs'],
            'notes' => 'Retired in 0.45.0: MyAPES Account is the only product name. Historic release notes keep the old name.',
        ],
        [
            'pattern' => 'Super Admin',
            'canonical' => 'Admin',
            'contexts' => ['nav'],
            'notes' => 'Retire as a nav door or Admin page-family label. Keep when naming the superadmin.access tier or protected role.',
        ],
        [
            'pattern' => 'sub-core',
            'canonical' => 'Module',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Organisation area is Module.',
        ],
        [
            'pattern' => 'subcore',
            'canonical' => 'Module',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Organisation area is Module.',
        ],
        [
            'pattern' => 'service area',
            'canonical' => 'Module',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'When it means an organisation area.',
        ],
        [
            'pattern' => 'module type',
            'canonical' => 'Plugin',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Reusable feature is Plugin.',
        ],
        [
            'pattern' => 'module instance',
            'canonical' => 'Plugin enablement',
            'contexts' => ['admin_ui'],
            'notes' => 'One module × one plugin switched on.',
        ],
        [
            'pattern' => 'module settings',
            'canonical' => 'Plugin settings',
            'contexts' => ['admin_ui'],
            'notes' => 'Admin → Plugins settings copy.',
        ],
        [
            'pattern' => 'Service user',
            'canonical' => 'Public user',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Prefer Public user in Admin filters and labels.',
        ],
        [
            'pattern' => 'Recruit manage',
            'canonical' => 'Recruitment',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Retired in 0.41.3: one Recruitment primary with a role-gated submenu replaces the separate manage primary.',
        ],
        [
            'pattern' => 'Jobs',
            'canonical' => 'Recruitment',
            'contexts' => ['nav', 'public_ui'],
            'notes' => 'Product name is Recruitment / Open roles; Jobs stays a search synonym later (#274).',
        ],
        [
            'pattern' => 'Helpdesk',
            'canonical' => 'Tickets',
            'contexts' => ['nav', 'public_ui', 'admin_ui'],
            'notes' => 'Product name is Tickets; Helpdesk stays a search synonym later (#274).',
        ],
    ],
];
