<?php

/**
 * Public Microsoft Bookings pages (#488).
 */
return [
    'nav' => 'Bookings',
    'keywords' => ['book', 'booking', 'appointment', 'meeting', 'schedule', 'visit', 'interview'],
    'title' => 'Bookings',
    'intro' => 'Book a meeting or appointment with APES. Choose the service you need.',
    'nav_label' => 'Bookings pages',
    'open' => 'Open :service',
    'schedule_online' => 'Schedule online',
    'new_tab' => '(opens in a new tab)',
    'fallback' => 'If the booking form does not load below, use Schedule online to book on the Microsoft Bookings site.',
    'frame_title' => ':service booking form',
    'pages' => [
        'apes' => [
            'title' => 'APES Bookings',
            'intro' => 'General meetings with APES CIC, including partnerships, fundraising, educational visits and student interviews.',
        ],
        'shelter_and_rescue' => [
            'title' => 'APES Shelter and Rescue bookings',
            'intro' => 'Appointments for adoption, surrender, rescue and fostering.',
        ],
        'pet_care_clinic' => [
            'title' => 'APES Pet Care Clinic bookings',
            'intro' => 'Clinic appointments and consultations.',
        ],
    ],
];
