<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum service tokens for /api/v1 (#638)
|--------------------------------------------------------------------------
|
| The API is for other APES apps, never the browser: there are no stateful
| domains and no session guard, so only a bearer token issued in Admin →
| Settings → API clients (#639) is accepted. Each token carries its own
| expiry; the prefix lets secret scanning spot a leaked token.
|
*/

return [

    'routes' => false,

    'stateful' => [],

    'guard' => [],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'myapes_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
