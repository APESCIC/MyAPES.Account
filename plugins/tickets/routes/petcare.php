<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\TicketController;

/*
| Pet Care Tickets staff routes (#289).
| Live prefix /petcare/tickets — names petcare.tickets.*
*/

Route::get('tickets', [TicketController::class, 'index'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.index');
Route::post('tickets', [TicketController::class, 'store'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.store');
Route::get('tickets/{ticket}/owner-candidates', [TicketController::class, 'ownerCandidates'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.owner-candidates');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.show');
Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'tickets')
    ->name('tickets.update');
