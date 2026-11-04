<?php

/**
 * Per-plugin settings defaults for this module (#472). Reset to defaults
 * restores these; admins manage the live list under Admin, Plugins.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'tickets' => [
        'service_areas' => [
            ['key' => 'appointment', 'label' => 'Appointment'],
            ['key' => 'consultation', 'label' => 'Consultation'],
            ['key' => 'prescription', 'label' => 'Prescription'],
            ['key' => 'billing', 'label' => 'Billing'],
            ['key' => 'follow_up', 'label' => 'Follow Up'],
            ['key' => 'other', 'label' => 'Other'],
        ],
    ],
];
