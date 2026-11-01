<?php

use Illuminate\Support\Facades\Route;
use Src\Finance\Invoice\Controllers\InvoiceController;
use Src\Finance\Invoice\Controllers\InvoiceSeriesController;

Route::prefix('api/v1/finance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])
            ->middleware('permission:invoices.view');

        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
            ->whereNumber('invoice')
            ->middleware('permission:invoices.view');

        Route::post('invoices/{invoice}/retry', [InvoiceController::class, 'retry'])
            ->whereNumber('invoice')
            ->middleware('permission:invoices.retry');

        Route::get('invoices/{invoice}/download/{type}', [InvoiceController::class, 'download'])
            ->whereNumber('invoice')
            ->middleware('permission:invoices.view');

        Route::get('invoice-series', [InvoiceSeriesController::class, 'index'])
            ->middleware('permission:invoices.view');

        Route::post('invoice-series', [InvoiceSeriesController::class, 'store'])
            ->middleware('permission:invoices.series');

        Route::put('invoice-series/{series}', [InvoiceSeriesController::class, 'update'])
            ->whereNumber('series')
            ->middleware('permission:invoices.series');
    });
