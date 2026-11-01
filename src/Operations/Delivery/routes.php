<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Delivery\Controllers\DeliveryController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:delivery.view')->group(function () {
            Route::get('deliveries', [DeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery');
            Route::get('deliveries/{delivery}/evidence/{type}', [DeliveryController::class, 'downloadEvidence'])
                ->whereNumber('delivery')
                ->whereIn('type', ['signature', 'photo']);
        });

        Route::middleware('permission:delivery.assign')->group(function () {
            Route::post('deliveries/assign', [DeliveryController::class, 'assign']);
        });

        Route::middleware('permission:delivery.execute')->group(function () {
            Route::get('deliveries/route-sheet', [DeliveryController::class, 'routeSheet']);
            Route::post('deliveries/batch', [DeliveryController::class, 'batch']);
            Route::post('deliveries/{delivery}/evidence', [DeliveryController::class, 'uploadEvidence'])->whereNumber('delivery');
            Route::post('deliveries/{delivery}/start', [DeliveryController::class, 'start'])->whereNumber('delivery');
            Route::post('deliveries/{delivery}/complete', [DeliveryController::class, 'complete'])->whereNumber('delivery');
            Route::post('deliveries/{delivery}/fail', [DeliveryController::class, 'fail'])->whereNumber('delivery');
        });
    });
