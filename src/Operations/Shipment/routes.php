<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Shipment\Controllers\EmbarkController;
use Src\Operations\Shipment\Controllers\ShipmentController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:shipments.view')->group(function () {
            Route::get('shipments', [ShipmentController::class, 'index']);
            Route::get('shipments/{ticketCode}', [ShipmentController::class, 'show']);
            Route::get('embarks', [EmbarkController::class, 'index']);
            Route::get('embarks/{embark}', [EmbarkController::class, 'show'])->whereNumber('embark');
        });

        Route::middleware('permission:inventory.manage')->group(function () {
            Route::get('agencies/{agency}/inventory', [ShipmentController::class, 'inventory'])->whereNumber('agency');
        });

        Route::middleware('permission:shipments.embark')->group(function () {
            Route::post('embarks', [EmbarkController::class, 'store']);
        });

        Route::middleware('permission:shipments.disembark')->group(function () {
            Route::post('embarks/{embark}/disembark', [EmbarkController::class, 'disembark'])->whereNumber('embark');
        });
    });
