<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\PublicTicketEntryController;

/*
| Public "Open a ticket" flow (#662–#665).
| Live paths /open-a-ticket, /open-a-ticket/{service}/{area}/start — names tickets.public.*
| Mounted from routes/web.php (guest-ok for index); store requires a public account.
*/

Route::get('/open-a-ticket', [PublicTicketEntryController::class, 'index'])
    ->name('tickets.public.index');
Route::post('/open-a-ticket', [PublicTicketEntryController::class, 'store'])
    ->middleware(['auth', 'account.public', 'throttle:20,1'])
    ->name('tickets.public.store');
Route::get('/open-a-ticket/{service}/{area}/start', [PublicTicketEntryController::class, 'start'])
    ->where(['service' => '[a-z-]+', 'area' => '[a-z_]+'])
    ->middleware('account.public')
    ->name('tickets.public.start');
