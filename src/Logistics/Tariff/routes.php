<?php

use Illuminate\Support\Facades\Route;
use Src\Logistics\Tariff\Controllers\TariffController;

/**
 * Tarifarios. Todo el recurso exige `tariffs.manage` (lectura incluida): el
 * precio es información comercial y `spec/04-modulos/core-logistica/05-api.md`
 * no define un perfil de solo lectura.
 */
Route::prefix('api/v1/logistics')
    ->middleware(['auth:sanctum', 'permission:tariffs.manage'])
    ->group(function () {
        Route::apiResource('tariffs', TariffController::class);
    });
