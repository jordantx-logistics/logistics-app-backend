<?php

use Illuminate\Support\Facades\Route;
use Src\Incident\Controllers\IncidentController;

Route::prefix('api/v1/incidents')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::middleware('permission:incidents.view')->group(function (): void {
            Route::get('/', [IncidentController::class, 'index']);
            Route::get('{incident}', [IncidentController::class, 'show'])->whereNumber('incident');
        });

        Route::middleware('permission:incidents.manage')->group(function (): void {
            Route::post('/', [IncidentController::class, 'store']);
            Route::get('{incident}/assignees', [IncidentController::class, 'assignees'])->whereNumber('incident');
            Route::post('{incident}/assign', [IncidentController::class, 'assign'])->whereNumber('incident');
            Route::post('{incident}/start', [IncidentController::class, 'start'])->whereNumber('incident');
            Route::post('{incident}/resolve', [IncidentController::class, 'resolve'])->whereNumber('incident');
            Route::post('{incident}/close', [IncidentController::class, 'close'])->whereNumber('incident');
            Route::post('{incident}/reopen', [IncidentController::class, 'reopen'])->whereNumber('incident');
        });
    });
