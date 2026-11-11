<?php

/*
|--------------------------------------------------------------------------
| Admin settings store (#732)
|--------------------------------------------------------------------------
|
| Settings admins edit in Admin → Settings live in the app_settings table;
| secrets are encrypted there. A non-empty environment value always wins,
| so the raw values are captured here (null when unset) and still work
| after config:cache. Add a line when a new setting has an .env override.
|
*/

return [

    'cache_seconds' => 3600,

    'env' => [
        'INBOUND_MAIL_ENABLED' => env('INBOUND_MAIL_ENABLED'),
        'INBOUND_MAIL_ADDRESS' => env('INBOUND_MAIL_ADDRESS'),
        'INBOUND_MAIL_PLUS_ADDRESSING' => env('INBOUND_MAIL_PLUS_ADDRESSING'),
        'INBOUND_MAIL_IMAP_HOST' => env('INBOUND_MAIL_IMAP_HOST'),
        'INBOUND_MAIL_IMAP_PORT' => env('INBOUND_MAIL_IMAP_PORT'),
        'INBOUND_MAIL_IMAP_ENCRYPTION' => env('INBOUND_MAIL_IMAP_ENCRYPTION'),
        'INBOUND_MAIL_IMAP_VALIDATE_CERT' => env('INBOUND_MAIL_IMAP_VALIDATE_CERT'),
        'INBOUND_MAIL_IMAP_USERNAME' => env('INBOUND_MAIL_IMAP_USERNAME'),
        'INBOUND_MAIL_IMAP_PASSWORD' => env('INBOUND_MAIL_IMAP_PASSWORD'),
        'INBOUND_MAIL_IMAP_FOLDER' => env('INBOUND_MAIL_IMAP_FOLDER'),
    ],

];
