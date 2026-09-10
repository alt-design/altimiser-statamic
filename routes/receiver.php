<?php

use AltDesign\Altimiser\Http\Controllers\ChangesController;
use AltDesign\Altimiser\Http\Controllers\HealthController;
use AltDesign\Altimiser\Http\Controllers\PatchController;
use AltDesign\Altimiser\Http\Middleware\VerifySignature;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * These endpoints are authenticated by request signature, not by a session, and
 * the caller is another server rather than a browser.
 *
 * CSRF is stripped explicitly rather than trusted to stay absent. Depending on
 * provider boot order these routes can end up inside the application's web
 * middleware group, and when that happens every POST is rejected with a 419 that
 * looks nothing like an authentication problem. Both the framework class and the
 * published subclass are named, because removal matches on class name and an
 * application that still publishes its own would otherwise slip through.
 */
Route::prefix(config('altimiser.route_prefix'))
    ->middleware(VerifySignature::class)
    ->withoutMiddleware([
        VerifyCsrfToken::class,
        'App\Http\Middleware\VerifyCsrfToken',
    ])
    ->group(function () {
        Route::get('health', HealthController::class)->name('altimiser.health');
        Route::post('changes', ChangesController::class)->name('altimiser.changes');
        Route::post('patch', PatchController::class)->name('altimiser.patch');
    });
