<?php

use Illuminate\Support\Facades\Route;
use Src\Fleet\Expense\Controllers\ExpenseController;

Route::prefix('api/v1/fleet')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::middleware('permission:routes.view')->group(function (): void {
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->whereNumber('expense');
        });

        Route::middleware('permission:expenses.register')->group(function (): void {
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::post('expenses/{expense}/receipt', [ExpenseController::class, 'attachReceipt'])->whereNumber('expense');
        });

        Route::post('expenses/liquidate', [ExpenseController::class, 'liquidate'])
            ->middleware('permission:expenses.approve');
    });
