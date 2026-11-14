<?php

/*
|--------------------------------------------------------------------------
| Ticket and case replies by email (#589)
|--------------------------------------------------------------------------
|
| Replies to ticket and case emails go to one mailbox, which the app polls
| over IMAP. Everything is read from the environment; secrets are never
| committed. With INBOUND_MAIL_ENABLED off or no token key, outgoing mail
| keeps the no-reply Reply-To and the poller does nothing.
|
*/

return [

    'enabled' => (bool) env('INBOUND_MAIL_ENABLED', false),

    'address' => env('INBOUND_MAIL_ADDRESS', 'communications@myapes.me.uk'),

    // Whether the mail server delivers communications+token@ to the mailbox (#577).
    'plus_addressing' => (bool) env('INBOUND_MAIL_PLUS_ADDRESSING', true),

    // Dedicated HMAC key for reply tokens. Never APP_KEY.
    'token_key' => env('INBOUND_MAIL_TOKEN_KEY'),

    // Only Authentication-Results headers from this server are trusted.
    // Empty trusts the topmost header, which the receiving server adds last.
    'authserv_id' => env('INBOUND_MAIL_AUTHSERV_ID'),

    'require_authentication' => (bool) env('INBOUND_MAIL_REQUIRE_AUTH', true),

    // imap (default) or mailpit. mailpit reads a standalone local Mailpit over its REST API and works only on local
    // or testing with a loopback URL (#756); the Cloudron deploy preflight refuses it.
    'driver' => env('INBOUND_MAIL_DRIVER', 'imap'),

    'mailpit' => [
        'url' => env('INBOUND_MAIL_MAILPIT_URL', 'http://127.0.0.1:8025'),
        'timeout' => 10,
    ],

    // Cloudron offers IMAP on 993 with SSL/TLS only (#773); the form starts with these values.
    'imap' => [
        'host' => env('INBOUND_MAIL_IMAP_HOST') ?: 'mail.cloudron.apes.org.uk',
        'port' => (int) env('INBOUND_MAIL_IMAP_PORT', 993),
        'encryption' => env('INBOUND_MAIL_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('INBOUND_MAIL_IMAP_VALIDATE_CERT', true),
        'username' => env('INBOUND_MAIL_IMAP_USERNAME'),
        'password' => env('INBOUND_MAIL_IMAP_PASSWORD'),
        'folder' => env('INBOUND_MAIL_IMAP_FOLDER', 'INBOX'),
        // Seconds to connect and to wait for each answer, so a hanging server cannot stall a poll (#778).
        'timeout' => (int) env('INBOUND_MAIL_IMAP_TIMEOUT', 15),
    ],

    'poll_batch' => (int) env('INBOUND_MAIL_POLL_BATCH', 50),

    'max_message_bytes' => (int) env('INBOUND_MAIL_MAX_MESSAGE_BYTES', 120 * 1024 * 1024),

    'max_reply_characters' => 20000,

    'retention_days' => (int) env('INBOUND_MAIL_RETENTION_DAYS', 30),

    // Mail in the mailbox's Processed and Failed folders is deleted after this many days (#776).
    'folder_retention_days' => (int) env('INBOUND_MAIL_FOLDER_RETENTION_DAYS', 30),

    'rate_limits' => [
        'per_sender_per_hour' => (int) env('INBOUND_MAIL_SENDER_LIMIT', 20),
        'per_record_per_hour' => (int) env('INBOUND_MAIL_RECORD_LIMIT', 30),
        'notices_per_sender_per_hour' => 1,
    ],

];
