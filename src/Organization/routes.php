<?php

use Illuminate\Support\Facades\Route;
use Src\Organization\Controllers\AgencyController;

Route::prefix('api/v1/organization')
    ->middleware('auth:sanctum') // all organization routes require authentication
    ->group(function () {
        // Selector de agencias de los formularios: una orden o una ruta pueden ir a
        // una agencia fuera del ámbito de quien las registra. Antes de `{agency}`.
        Route::get('agencies/options', [AgencyController::class, 'options'])
            ->middleware('permission:agencies.view|orders.view|orders.create|routes.manage|fleet.manage|employees.manage|reports.view');

        Route::apiResource('agencies', AgencyController::class)
            ->middlewareFor(['index', 'show'], 'permission:agencies.view')
            ->middlewareFor(['store', 'update', 'destroy'], 'permission:agencies.manage');
    });
