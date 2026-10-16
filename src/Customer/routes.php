<?php

use Illuminate\Support\Facades\Route;
use Src\Customer\Controllers\CompanyController;
use Src\Customer\Controllers\PersonController;

Route::prefix('api/v1/customer')
    ->middleware('auth:api')
    ->group(function () {
        Route::apiResource('persons', PersonController::class)->only(['index', 'store']);
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store']);
    });
