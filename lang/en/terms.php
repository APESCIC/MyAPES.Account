<?php

/**
 * Canonical product labels for MyAPES Account (#267).
 *
 * Prefer __('terms.key') and trans_choice('terms.key', $n).
 * Human glossary: docs/glossary.md. Deprecated synonyms: config/glossary.php.
 */
return [
    'app_name' => 'MyAPES Account',
    'core' => 'Core',

    'module' => 'Module|Modules',
    'modules' => 'Modules',
    'plugin' => 'Plugin|Plugins',
    'plugins' => 'Plugins',
    'plugin_enablement' => 'Plugin enablement',
    'plugin_settings' => 'Plugin settings',

    'admin' => 'Admin',
    'super_admin' => 'Super Admin',

    'public_user' => 'Public user|Public users',
    'public_users' => 'Public users',
    'staff' => 'Staff',

    'job_role' => 'Job role|Job roles',
    'job_roles' => 'Job roles',
    'access_group' => 'Group|Groups',
    'access_groups' => 'Groups',
    'permission' => 'Permission|Permissions',
    'permissions' => 'Permissions',
    'permission_catalogue' => 'Permission catalogue',

    'recruitment_role' => 'Role|Roles',
    'recruitment_roles' => 'Roles',
    'open_roles' => 'Open roles',
    'my_applications' => 'My applications',
    'applications' => 'Applications',
    'recruitment' => 'Recruitment',

    'apes_cic' => 'APES CIC',
    'pet_care_clinic' => 'Pet Care Clinic',
    'pet_care_clinic_full' => 'APES Pet Care Clinic',
    'shelter_and_rescue' => 'Shelter and Rescue',
    'shelter_and_rescue_full' => 'APES Shelter and Rescue',

    'tickets' => 'Tickets',
    'cases' => 'Cases',
    'consultations' => 'Consultations',
    'pet_profiles' => 'Pet Profiles',

    'dashboard' => 'Dashboard',
    'profile' => 'Profile',
    'access' => 'Access',

    /*
     * Workflow display labels. Keys are the stored snake_case values; labels are Title Case.
     */
    'status' => [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'shortlisted' => 'Shortlisted',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        'draft' => 'Draft',
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'in_review' => 'In Review',
        'waiting_on_user' => 'Waiting on User',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],

    'priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'case_type' => [
        'adoption' => 'Adoption',
        'surrender' => 'Surrender',
        'rescue' => 'Rescue',
        'fostering' => 'Fostering',
    ],

    'recruitment_category' => [
        'staff' => 'Staff',
        'volunteer' => 'Volunteer',
        'student' => 'Student',
    ],

    'sex' => [
        'male' => 'Male',
        'female' => 'Female',
        'unknown' => 'Unknown',
    ],

    'neutering_status' => [
        'neutered' => 'Neutered',
        'not_neutered' => 'Not Neutered',
        'unknown' => 'Unknown',
    ],
];
