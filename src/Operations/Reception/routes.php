<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Reception\Controllers\ReceptionController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:shipments.view')->group(function () {
            Route::get('receptions', [ReceptionController::class, 'index']);
        });

        Route::middleware('permission:shipments.receive')->group(function () {
            Route::post('receptions', [ReceptionController::class, 'store']);
        });
    });
