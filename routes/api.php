<?php

use App\Core\Api\ApiRoutes;
use App\Http\Controllers\Api\V1\ApiClientInfoController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 for internal APES apps (#638). Read-only endpoints; each one
| names its ability. docs/api/openapi-v1.yaml must list every route here (#643).
*/

ApiRoutes::v1(function (): void {
    Route::get('me', ApiClientInfoController::class)->name('me');
});
