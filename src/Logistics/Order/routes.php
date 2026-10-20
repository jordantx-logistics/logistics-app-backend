<?php

use Illuminate\Support\Facades\Route;
use Src\Logistics\Order\Controllers\OrderController;

// Cada ruta declara su permiso: lectura (`orders.view`), alta y cotización
// (`orders.create`), confirmar, cancelar y definir reparto. Hay roles con unos
// y no con otros, así que no se agrupan bajo un permiso común.
Route::prefix('api/v1/logistics')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders', [OrderController::class, 'index']);
            // Antes que `orders/{order}`: `by-ticket` no es un id.
            Route::get('orders/by-ticket/{ticketCode}', [OrderController::class, 'byTicket']);
            Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        });

        Route::middleware('permission:orders.create')->group(function () {
            Route::post('orders', [OrderController::class, 'store']);
            Route::post('orders/quote', [OrderController::class, 'quote']);
        });

        Route::middleware('permission:orders.confirm')
            ->post('orders/{order}/confirm', [OrderController::class, 'confirm'])
            ->whereNumber('order');

        Route::middleware('permission:orders.cancel')
            ->post('orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->whereNumber('order');

        Route::middleware('permission:orders.update')
            ->patch('orders/{order}/delivery', [OrderController::class, 'updateDelivery'])
            ->whereNumber('order');
    });
