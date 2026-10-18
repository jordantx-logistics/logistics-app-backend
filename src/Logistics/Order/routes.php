<?php

use Illuminate\Support\Facades\Route;
use Src\Logistics\Order\Controllers\OrderController;

// Cada ruta declara su permiso: el listado es de lectura (`orders.view`) y la
// creación de escritura (`orders.create`), y hay roles con uno y no con el otro.
Route::prefix('api/v1/logistics')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:orders.view')
            ->get('orders', [OrderController::class, 'index']);

        Route::middleware('permission:orders.create')
            ->post('orders', [OrderController::class, 'store']);
    });
