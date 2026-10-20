<?php

use Illuminate\Support\Facades\Route;
use Src\Organization\Controllers\AgencyController;

Route::prefix('api/v1/organization')
    ->middleware('auth:sanctum') // all organization routes require authentication
    ->group(function () {
        Route::apiResource('agencies', AgencyController::class)
            ->middlewareFor(['index', 'show'], 'permission:agencies.view')
            ->middlewareFor(['store', 'update', 'destroy'], 'permission:agencies.manage');
    });
