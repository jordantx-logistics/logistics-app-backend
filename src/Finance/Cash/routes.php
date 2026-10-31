<?php

use Illuminate\Support\Facades\Route;
use Src\Finance\Cash\Controllers\CashClosureController;
use Src\Finance\Cash\Controllers\CashMovementController;
use Src\Finance\Cash\Controllers\CashSessionController;

Route::prefix('api/v1/finance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('cash-sessions/current', [CashSessionController::class, 'current'])
            ->middleware('permission:cash.open');

        Route::get('cash-sessions', [CashSessionController::class, 'index'])
            ->middleware('permission:cash.view');

        Route::post('cash-sessions', [CashSessionController::class, 'open'])
            ->middleware('permission:cash.open');

        Route::get('cash-sessions/{session}', [CashSessionController::class, 'show'])
            ->whereNumber('session')
            ->middleware('permission:cash.view');

        Route::post('cash-sessions/{session}/close', [CashSessionController::class, 'close'])
            ->whereNumber('session')
            ->middleware('permission:cash.close');

        Route::post('cash-movements', [CashMovementController::class, 'store'])
            ->middleware('permission:payments.create');

        Route::get('cash-closures', [CashClosureController::class, 'index'])
            ->middleware('permission:cash.view');

        Route::post('cash-closures/{closure}/approve', [CashClosureController::class, 'approve'])
            ->whereNumber('closure')
            ->middleware('permission:voids.approve');

        Route::post('cash-closures/{closure}/reject', [CashClosureController::class, 'reject'])
            ->whereNumber('closure')
            ->middleware('permission:voids.approve');

        Route::get('cash-closures/{closure}/report', [CashClosureController::class, 'report'])
            ->whereNumber('closure')
            ->middleware('permission:cash.view');
    });
