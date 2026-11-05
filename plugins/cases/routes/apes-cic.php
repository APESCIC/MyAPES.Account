<?php

use Illuminate\Support\Facades\Route;
use Plugins\Cases\Http\Controllers\CaseController;
use Plugins\Cases\Http\Controllers\CaseUpdateController;

/*
| APES CIC Cases staff routes (#289).
| Live prefix /apes-cic/cases — names apes-cic.cases.*
*/

Route::get('cases', [CaseController::class, 'index'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->name('cases.index');
Route::post('cases', [CaseController::class, 'store'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->middleware('account.public')
    ->name('cases.store');
Route::get('cases/{case}', [CaseController::class, 'show'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->name('cases.show');
Route::match(['put', 'patch'], 'cases/{case}', [CaseController::class, 'update'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->name('cases.update');
Route::delete('cases/{case}', [CaseController::class, 'destroy'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->name('cases.destroy');
Route::post('cases/{case}/updates', [CaseUpdateController::class, 'store'])
    ->defaults('subCoreKey', 'apes-cic')
    ->defaults('moduleKey', 'cases')
    ->name('cases.updates.store');
