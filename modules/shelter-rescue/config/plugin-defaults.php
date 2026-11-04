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
            ['key' => 'adoption', 'label' => 'Adoption'],
            ['key' => 'surrender', 'label' => 'Surrender'],
            ['key' => 'rescue', 'label' => 'Rescue'],
            ['key' => 'fostering', 'label' => 'Fostering'],
            ['key' => 'animal_welfare', 'label' => 'Animal Welfare'],
            ['key' => 'other', 'label' => 'Other'],
        ],
    ],
];
