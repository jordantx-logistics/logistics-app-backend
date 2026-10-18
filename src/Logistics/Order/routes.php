<?php

use Illuminate\Support\Facades\Route;
use Src\Logistics\Order\Controllers\OrderController;

Route::prefix('api/v1/logistics')
    ->middleware(['auth:sanctum', 'permission:orders.create'])
    ->group(function () {
        Route::post('orders', [OrderController::class, 'store']);
    });
