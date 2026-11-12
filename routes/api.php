<?php

use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiRoutes;
use App\Http\Controllers\Api\V1\ApiClientInfoController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\StaffController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 for internal APES apps (#638, #640). Read-only endpoints; each one
| names its ability and is limited to the client's organisation.
| docs/api/openapi-v1.yaml must list every route here (#643).
*/

ApiRoutes::v1(function (): void {
    Route::get('me', ApiClientInfoController::class)->name('me');

    Route::get('staff', [StaffController::class, 'index'])->middleware('abilities:'.ApiAbilities::STAFF_READ)->name('staff.index');
    Route::get('staff/{id}', [StaffController::class, 'show'])->whereNumber('id')->middleware('abilities:'.ApiAbilities::STAFF_READ)->name('staff.show');
    Route::get('groups', [StaffController::class, 'groups'])->middleware('abilities:'.ApiAbilities::GROUPS_READ)->name('groups.index');

    Route::get('users/lookup', [PeopleController::class, 'lookup'])->middleware('abilities:'.ApiAbilities::USERS_READ)->name('users.lookup');
    Route::get('users/{id}', [PeopleController::class, 'show'])->whereNumber('id')->middleware('abilities:'.ApiAbilities::USERS_READ)->name('users.show');
    Route::get('users/{id}/consents', [PeopleController::class, 'consents'])->whereNumber('id')->middleware('abilities:'.ApiAbilities::CONSENT_READ)->name('users.consents');

    require base_path('plugins/pet-profiles/routes/api.php');
    require base_path('plugins/tickets/routes/api.php');
    require base_path('plugins/cases/routes/api.php');
});
