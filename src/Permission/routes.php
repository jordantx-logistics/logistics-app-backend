<?php

use Illuminate\Support\Facades\Route;
use Src\Permission\Controllers\PermissionController;
use Src\Permission\Controllers\RoleController;

Route::prefix('api/v1/permission')->middleware('auth:api')->group(function () {
    // Roles endpoints
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/', [RoleController::class, 'store']);
        Route::get('/{id}', [RoleController::class, 'show']);
        Route::put('/{id}', [RoleController::class, 'update']);
        Route::delete('/{id}', [RoleController::class, 'destroy']);

        // Role permissions
        Route::prefix('{id}')->group(function () {
            Route::post('permissions', [RoleController::class, 'assignPermissions']);
        });
    });

    // Permissions endpoints
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::get('/{id}', [PermissionController::class, 'show']);
        Route::put('/{id}', [PermissionController::class, 'update']);
        Route::delete('/{id}', [PermissionController::class, 'destroy']);
    });

    // User role assignment
    Route::prefix('users')->group(function () {
        Route::prefix('{id}')->group(function () {
            Route::post('roles', [RoleController::class, 'assignRole']);
        });
    });
});
