<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\PublicTicketEntryController;

/*
| Public "Open a ticket" page (#466).
| Live paths /open-a-ticket, /open-a-ticket/{service}/{area}/start — names tickets.public.*
| Mounted from routes/web.php (guest-ok); each service checks its own plugin enablement.
*/

Route::get('/open-a-ticket', [PublicTicketEntryController::class, 'index'])
    ->name('tickets.public.index');
Route::get('/open-a-ticket/{service}/{area}/start', [PublicTicketEntryController::class, 'start'])
    ->where(['service' => '[a-z-]+', 'area' => '[a-z_]+'])
    ->middleware('account.public')
    ->name('tickets.public.start');
