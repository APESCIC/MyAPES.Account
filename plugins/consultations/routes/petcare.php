<?php

use Illuminate\Support\Facades\Route;
use Plugins\Consultations\Http\Controllers\ConsultationController;

/*
| Pet Care Consultations staff routes (#290).
| Live prefix /petcare/consultations — names petcare.consultations.*
*/

Route::get('consultations', [ConsultationController::class, 'index'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'consultations')
    ->name('consultations.index');
Route::post('consultations', [ConsultationController::class, 'store'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'consultations')
    ->name('consultations.store');
Route::get('consultations/{consultation}', [ConsultationController::class, 'show'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'consultations')
    ->name('consultations.show');
Route::match(['put', 'patch'], 'consultations/{consultation}', [ConsultationController::class, 'update'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'consultations')
    ->name('consultations.update');
Route::post('consultations/{consultation}/vet-review', [ConsultationController::class, 'markVetReviewed'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'consultations')
    ->name('consultations.vet-review');
