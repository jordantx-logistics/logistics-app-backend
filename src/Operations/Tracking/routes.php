<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Tracking\Controllers\TrackingController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:tracking.scan')->group(function () {
            Route::post('scans/sync', [TrackingController::class, 'sync']);
        });

        Route::middleware('permission:tracking.view')->group(function () {
            Route::get('tracking/{ticketCode}', [TrackingController::class, 'show']);
        });
    });
