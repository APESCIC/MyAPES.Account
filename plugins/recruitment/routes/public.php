<?php

use Illuminate\Support\Facades\Route;
use Plugins\Recruitment\Http\Controllers\RecruitmentBoardController;

/*
| Public Recruitment board (#290).
| Live paths /recruitment, /recruitment/{id} — names recruitment.index|show
| Mounted from routes/web.php behind plugin.enabled:apes-cic,recruitment (guest-ok).
*/

Route::get('/recruitment', [RecruitmentBoardController::class, 'index'])
    ->name('recruitment.index');
Route::get('/recruitment/{recruitmentRole}', [RecruitmentBoardController::class, 'show'])
    ->whereNumber('recruitmentRole')
    ->name('recruitment.show');
Route::get('/recruitment/{recruitmentRole}/apply/start', [RecruitmentBoardController::class, 'startApply'])
    ->whereNumber('recruitmentRole')
    ->name('recruitment.apply.start');
