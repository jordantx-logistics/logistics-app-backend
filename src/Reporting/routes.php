<?php

use Illuminate\Support\Facades\Route;
use Src\Reporting\Controllers\DashboardController;
use Src\Reporting\Controllers\ReportExportController;

Route::prefix('api/v1')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('dashboard/kpis', [DashboardController::class, 'kpis'])
            ->middleware('permission:dashboard.view');

        Route::prefix('reports')
            ->middleware('permission:reports.view')
            ->group(function (): void {
                Route::get('catalog', [ReportExportController::class, 'catalog']);
                Route::get('exports', [ReportExportController::class, 'index']);
                Route::post('exports', [ReportExportController::class, 'store']);
                Route::get('exports/{export}', [ReportExportController::class, 'show'])->whereNumber('export');
                Route::get('exports/{export}/download', [ReportExportController::class, 'download'])->whereNumber('export');
            });
    });
