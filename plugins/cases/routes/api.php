<?php

use App\Core\Api\ApiAbilities;
use Illuminate\Support\Facades\Route;
use Plugins\Cases\Http\Controllers\Api\CaseApiController;

/*
| Cases in /api/v1 (#640), inside ApiRoutes::v1() in routes/api.php.
| {ref} is the case number shown in emails as "Case #123".
*/

Route::get('cases/{ref}', [CaseApiController::class, 'show'])->whereNumber('ref')->middleware('abilities:'.ApiAbilities::CASES_READ)->name('cases.show');
