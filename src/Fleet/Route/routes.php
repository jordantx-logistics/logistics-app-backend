<?php

use Illuminate\Support\Facades\Route;
use Src\Fleet\Route\Controllers\RouteController;

Route::prefix('api/v1/fleet')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::middleware('permission:routes.view')->group(function (): void {
            Route::get('routes', [RouteController::class, 'index']);
            Route::get('routes/{route}', [RouteController::class, 'show'])->whereNumber('route');
            Route::get('routes/{route}/summary', [RouteController::class, 'summary'])->whereNumber('route');
            Route::get('routes/{route}/mileage/{log}/photo', [RouteController::class, 'downloadPhoto'])
                ->whereNumber(['route', 'log']);
        });

        Route::middleware('permission:routes.manage')->group(function (): void {
            Route::post('routes', [RouteController::class, 'store']);
            Route::post('routes/{route}/assign', [RouteController::class, 'assign'])->whereNumber('route');
            Route::post('routes/{route}/cancel', [RouteController::class, 'cancel'])->whereNumber('route');
        });

        Route::post('sync', [RouteController::class, 'sync'])
            ->middleware('permission:routes.execute|expenses.register');

        Route::middleware('permission:routes.execute')->group(function (): void {
            Route::post('routes/{route}/start', [RouteController::class, 'start'])->whereNumber('route');
            Route::post('routes/{route}/segments/{segment}/complete', [RouteController::class, 'completeSegment'])
                ->whereNumber(['route', 'segment']);
            Route::post('routes/{route}/finish', [RouteController::class, 'finish'])->whereNumber('route');
            Route::post('routes/{route}/mileage', [RouteController::class, 'registerMileage'])->whereNumber('route');
        });
    });
