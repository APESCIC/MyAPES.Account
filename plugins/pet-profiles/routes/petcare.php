<?php

use Illuminate\Support\Facades\Route;
use Plugins\PetProfiles\Http\Controllers\PetProfileController;

/*
| Pet Care Clinic Pet Profiles staff routes (#291).
| Live prefix /petcare/pets — names petcare.pets.* — mounted from routes/web.php.
*/

Route::get('pet-profiles', static fn () => redirect()->route('petcare.pets.index', [], 301))
    ->name('pet-profiles');
Route::get('pets/{pet}/photo', [PetProfileController::class, 'photo'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.photo');
Route::get('pets', [PetProfileController::class, 'index'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.index');
Route::post('pets', [PetProfileController::class, 'store'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'pet-profiles')
    ->middleware('account.public')
    ->name('pets.store');
Route::get('pets/{pet}', [PetProfileController::class, 'show'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.show');
Route::match(['put', 'patch'], 'pets/{pet}', [PetProfileController::class, 'update'])
    ->defaults('subCoreKey', 'pet-care-clinic')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.update');
