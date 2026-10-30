<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\TicketController;

/*
| APES CIC Tickets staff routes (#289).
| Live prefix /apes-cic/tickets — names apes-cic.tickets.*
*/

Route::get('tickets', [TicketController::class, 'index'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.index');
Route::post('tickets', [TicketController::class, 'store'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.store');
Route::get('tickets/{ticket}/owner-candidates', [TicketController::class, 'ownerCandidates'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.owner-candidates');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.show');
Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.update');
Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.destroy');
