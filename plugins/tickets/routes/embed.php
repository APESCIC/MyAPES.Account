<?php

use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Http\Controllers\EmbedTicketController;
use Plugins\Tickets\Http\Middleware\ApplyEmbedFrameAncestors;

/*
| Guest embed ticket path (#682–#692). Separate from login-first /open-a-ticket.
| Names: tickets.embed.*
*/

Route::get('/embed/tickets/loader.js', [EmbedTicketController::class, 'loader'])
    ->name('tickets.embed.loader');

Route::get('/embed/tickets/verify/{token}', [EmbedTicketController::class, 'verify'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('tickets.embed.verify');

Route::middleware([ApplyEmbedFrameAncestors::class])->group(function (): void {
    Route::get('/embed/tickets/{publicKey}/token', [EmbedTicketController::class, 'mintToken'])
        ->where('publicKey', 'tef_[A-Za-z0-9]+')
        ->middleware('throttle:60,1')
        ->name('tickets.embed.token');

    Route::get('/embed/tickets/{publicKey}', [EmbedTicketController::class, 'show'])
        ->where('publicKey', 'tef_[A-Za-z0-9]+')
        ->name('tickets.embed.show');

    Route::post('/embed/tickets/{publicKey}', [EmbedTicketController::class, 'store'])
        ->where('publicKey', 'tef_[A-Za-z0-9]+')
        ->middleware('throttle:20,1')
        ->name('tickets.embed.store');
});
