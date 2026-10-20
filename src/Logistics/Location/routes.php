<?php

use Illuminate\Support\Facades\Route;
use Src\Logistics\Location\Controllers\AgencyCoverageController;
use Src\Logistics\Location\Controllers\LocationController;
use Src\Logistics\Location\Controllers\ZoneController;

/**
 * Zonas, ubicaciones y coberturas de agencia.
 *
 * Todo el contexto comparte el permiso `locations.manage`: son datos maestros
 * de la operación, no hay perfil de solo lectura para ellos
 * (`spec/04-modulos/core-logistica/05-api.md`). No hay `destroy`: las filas se
 * desactivan con `is_active` o se reagrupan, nunca se borran (una orden
 * histórica puede apuntar a esa ubicación).
 */
Route::prefix('api/v1/logistics')
    ->middleware(['auth:sanctum', 'permission:locations.manage'])
    ->group(function () {
        Route::apiResource('zones', ZoneController::class)->only(['index', 'store', 'update']);

        Route::apiResource('locations', LocationController::class)->only(['index', 'store', 'update']);

        Route::apiResource('agency-coverages', AgencyCoverageController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['agency-coverages' => 'coverage']);
    });
