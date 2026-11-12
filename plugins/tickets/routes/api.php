<?php

use App\Core\Api\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\Api\TicketApiController;

/*
| Tickets in /api/v1 (#640), inside ApiRoutes::v1() in routes/api.php.
| {ref} is the ticket number shown in emails as "Ticket #123".
*/

Route::get('tickets', [TicketApiController::class, 'index'])->middleware('abilities:'.ApiAbilities::TICKETS_READ)->name('tickets.index');
Route::get('tickets/{ref}', [TicketApiController::class, 'show'])->whereNumber('ref')->middleware('abilities:'.ApiAbilities::TICKETS_READ)->name('tickets.show');
