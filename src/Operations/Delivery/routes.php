<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Delivery\Controllers\DeliveryController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:delivery.view')->group(function () {
            Route::get('deliveries', [DeliveryController::class, 'index']);
            Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery');
        });

        Route::middleware('permission:delivery.assign')->group(function () {
            Route::post('deliveries/assign', [DeliveryController::class, 'assign']);
        });

        Route::middleware('permission:delivery.execute')->group(function () {
            Route::post('deliveries/{delivery}/start', [DeliveryController::class, 'start'])->whereNumber('delivery');
            Route::post('deliveries/{delivery}/complete', [DeliveryController::class, 'complete'])->whereNumber('delivery');
            Route::post('deliveries/{delivery}/fail', [DeliveryController::class, 'fail'])->whereNumber('delivery');
        });
    });
