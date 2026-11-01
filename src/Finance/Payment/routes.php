<?php

use Illuminate\Support\Facades\Route;
use Src\Finance\Payment\Controllers\PaymentController;

Route::prefix('api/v1/finance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('orders/{order}/payment-status', [PaymentController::class, 'status'])
            ->whereNumber('order')
            ->middleware('permission:payments.view');

        Route::get('payments', [PaymentController::class, 'index'])
            ->middleware('permission:payments.view');

        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware('permission:payments.create');

        Route::get('payments/{payment}', [PaymentController::class, 'show'])
            ->whereNumber('payment')
            ->middleware('permission:payments.view');
    });
