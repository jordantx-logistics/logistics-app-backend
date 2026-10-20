<?php

use Illuminate\Support\Facades\Route;
use Src\Customer\Controllers\CompanyController;
use Src\Customer\Controllers\CustomerController;
use Src\Customer\Controllers\PersonController;

Route::prefix('api/v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        // Agregado de clientes (Fase 3): el remitente/destinatario de una orden.
        Route::apiResource('customers', CustomerController::class)
            ->middlewareFor('index', 'permission:customers.view')
            ->middlewareFor('show', 'permission:customers.view')
            ->middlewareFor('store', 'permission:customers.manage')
            ->middlewareFor('update', 'permission:customers.manage')
            ->middlewareFor('destroy', 'permission:customers.manage');

        // Altas rápidas del MVP: crean la persona/empresa **y** su cliente en la
        // misma transacción (`CreateCustomerUseCase`), para que lo dado de alta
        // aquí se pueda usar como remitente de una orden.
        Route::prefix('customer')->group(function () {
            Route::apiResource('persons', PersonController::class)
                ->only(['index', 'store'])
                ->middlewareFor('index', 'permission:customers.view')
                ->middlewareFor('store', 'permission:customers.manage');

            Route::apiResource('companies', CompanyController::class)
                ->only(['index', 'store'])
                ->middlewareFor('index', 'permission:customers.view')
                ->middlewareFor('store', 'permission:customers.manage');
        });
    });
