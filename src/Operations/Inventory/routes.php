<?php

use Illuminate\Support\Facades\Route;
use Src\Operations\Inventory\Controllers\InventoryCountController;

Route::prefix('api/v1/operations')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::get('inventory-counts', [InventoryCountController::class, 'index']);
            Route::post('inventory-counts', [InventoryCountController::class, 'store']);
            Route::get('inventory-counts/{count}', [InventoryCountController::class, 'show'])->whereNumber('count');
            Route::post('inventory-counts/{count}/finish', [InventoryCountController::class, 'finish'])->whereNumber('count');
        });
    });
