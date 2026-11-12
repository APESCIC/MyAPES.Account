<?php

use App\Core\Api\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Plugins\PetProfiles\Http\Controllers\Api\PetApiController;

/*
| Pet Profiles in /api/v1 (#640), inside ApiRoutes::v1() in routes/api.php.
*/

Route::get('users/{id}/pets', [PetApiController::class, 'forUser'])->whereNumber('id')->middleware('abilities:'.ApiAbilities::PETS_READ)->name('users.pets');
Route::get('pets/{id}', [PetApiController::class, 'show'])->whereNumber('id')->middleware('abilities:'.ApiAbilities::PETS_READ)->name('pets.show');
