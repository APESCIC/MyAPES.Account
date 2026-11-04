<?php

/**
 * Staff caller lookup and PIN verification (#477).
 */
return [
    'nav' => 'Callers',
    'title' => 'Callers',
    'intro' => 'Find the person on the phone, then check two digits of their phone verification PIN before you change anything.',
    'search_label' => 'Find a caller',
    'search_placeholder' => 'Name, email or username',
    'search' => 'Search',
    'results' => 'Matching accounts',
    'no_results' => 'No public accounts match that search.',
    'back' => 'Back to callers',
    'verify_heading' => 'Check who is calling',
    'ask_for_digits' => 'Ask the caller for digit :first and digit :second of their PIN, then type what they say.',
    'digit_label' => 'Digit :position',
    'check' => 'Check digits',
    'digits_hint' => 'You never see any PIN digits. Three wrong attempts pause checking for 15 minutes.',
    'no_pin' => 'This person has not set a phone verification PIN. Ask them to set one on their profile, or follow your usual identity checks.',
    'locked' => 'Too many wrong attempts. Checking is paused for about :minutes minutes.',
    'verified' => 'Caller verified.',
    'result' => [
        'verified' => 'Caller verified.',
        'failed' => 'Those digits did not match. Ask for the two new digits shown.',
        'locked' => 'Too many wrong attempts. Checking is paused for 15 minutes.',
    ],
];
