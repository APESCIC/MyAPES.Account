<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\TicketController;

/*
| Shelter Tickets staff routes (#289).
| Live prefix /shelter/tickets — names shelter.tickets.*
*/

Route::get('tickets', [TicketController::class, 'index'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.index');
Route::post('tickets', [TicketController::class, 'store'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.store');
Route::get('tickets/{ticket}/owner-candidates', [TicketController::class, 'ownerCandidates'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.owner-candidates');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.show');
Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.update');
