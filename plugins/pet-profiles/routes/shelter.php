<?php

use Illuminate\Support\Facades\Route;
use Plugins\PetProfiles\Http\Controllers\PetProfileController;

/*
| Shelter Pet Profiles staff routes (#291).
| Live prefix /shelter/pets — names shelter.pets.* — mounted from routes/web.php.
*/

Route::get('pet-profiles', static fn () => redirect()->route('shelter.pets.index', [], 301))
    ->name('pet-profiles');
Route::get('pets/{pet}/photo', [PetProfileController::class, 'photo'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.photo');
Route::get('pets', [PetProfileController::class, 'index'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.index');
Route::post('pets', [PetProfileController::class, 'store'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'pet-profiles')
    ->middleware('account.public')
    ->name('pets.store');
Route::get('pets/{pet}', [PetProfileController::class, 'show'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.show');
Route::match(['put', 'patch'], 'pets/{pet}', [PetProfileController::class, 'update'])
    ->defaults('subCoreKey', 'shelter-rescue')
    ->defaults('moduleKey', 'pet-profiles')
    ->name('pets.update');
