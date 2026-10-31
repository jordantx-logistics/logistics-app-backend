<?php

use Illuminate\Support\Facades\Route;
use Src\Finance\Void\Controllers\VoidController;

Route::prefix('api/v1/finance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('voids', [VoidController::class, 'index'])
            ->middleware('permission:voids.view');

        Route::post('voids', [VoidController::class, 'store'])
            ->middleware('permission:voids.request');

        Route::get('voids/{void}', [VoidController::class, 'show'])
            ->whereNumber('void')
            ->middleware('permission:voids.view');

        Route::post('voids/{void}/approve', [VoidController::class, 'approve'])
            ->whereNumber('void')
            ->middleware('permission:voids.approve');

        Route::post('voids/{void}/reject', [VoidController::class, 'reject'])
            ->whereNumber('void')
            ->middleware('permission:voids.approve');
    });
