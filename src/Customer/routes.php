<?php

use Illuminate\Support\Facades\Route;
use Src\Customer\Controllers\CompanyController;
use Src\Customer\Controllers\PersonController;

Route::prefix('api/v1/customer')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::apiResource('persons', PersonController::class)
            ->only(['index', 'store'])
            ->middlewareFor('index', 'permission:persons.view')
            ->middlewareFor('store', 'permission:persons.manage');

        Route::apiResource('companies', CompanyController::class)
            ->only(['index', 'store'])
            ->middlewareFor('index', 'permission:companies.view')
            ->middlewareFor('store', 'permission:companies.manage');
    });
