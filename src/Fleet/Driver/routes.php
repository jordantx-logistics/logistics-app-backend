<?php

use Illuminate\Support\Facades\Route;
use Src\Fleet\Driver\Controllers\DriverController;

Route::prefix('api/v1/fleet')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        // Quien asigna rutas necesita ver los choferes, aunque no los administre.
        Route::get('drivers', [DriverController::class, 'index'])
            ->middleware('permission:fleet.manage|routes.manage');

        Route::post('drivers', [DriverController::class, 'store'])
            ->middleware('permission:fleet.manage');

        Route::patch('drivers/{driver}', [DriverController::class, 'update'])
            ->whereNumber('driver')
            ->middleware('permission:fleet.manage');
    });
