<?php

use Illuminate\Support\Facades\Route;
use Src\Fleet\Vehicle\Controllers\VehicleController;

Route::prefix('api/v1/fleet')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        // Quien asigna rutas necesita ver los vehículos, aunque no los administre.
        Route::get('vehicles', [VehicleController::class, 'index'])
            ->middleware('permission:fleet.manage|routes.manage');

        Route::post('vehicles', [VehicleController::class, 'store'])
            ->middleware('permission:fleet.manage');

        Route::patch('vehicles/{vehicle}/status', [VehicleController::class, 'updateStatus'])
            ->whereNumber('vehicle')
            ->middleware('permission:fleet.manage');

        Route::post('vehicles/{vehicle}/odometer-correction', [VehicleController::class, 'correctOdometer'])
            ->whereNumber('vehicle')
            ->middleware('permission:fleet.manage');
    });
