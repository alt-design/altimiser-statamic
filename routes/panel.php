<?php

use AltDesign\Altimiser\Http\Controllers\ReviewPageController;
use Illuminate\Support\Facades\Route;
use Statamic\Http\Middleware\CP\SwapExceptionHandler;

/*
 * A control panel page of its own rather than a dashboard widget, so the review
 * queue is somewhere an editor can navigate to rather than something they have to
 * notice.
 *
 * All three layers, matching Statamic's own CP routes. The authenticated group
 * runs the authorisation check, but the guard and session that give it a user to
 * authorise live in the plain statamic.cp group. Register only the former and
 * every request is refused for lack of permission, including a super user's,
 * which is a thoroughly misleading way for a missing middleware group to present.
 */
Route::name('statamic.cp.')
    ->prefix(config('statamic.cp.route', 'cp'))
    ->middleware([
        SwapExceptionHandler::class,
        'statamic.cp',
        'statamic.cp.authenticated',
    ])
    ->group(function () {
        Route::get('altimiser', ReviewPageController::class)->name('altimiser.index');
    });
