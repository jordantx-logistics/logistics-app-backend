<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\Role\Controllers\PermissionController;
use Src\Identity\Role\Controllers\RoleController;

Route::prefix('api/v1/permission')->middleware('auth:sanctum')->group(function () {
    // `whereNumber` no es cosmético: los controladores reciben `int $id`, así que
    // un id no numérico produciría un TypeError (500). Con la restricción la URL
    // no casa con ninguna ruta y la respuesta es 404, como un id inexistente.

    // Roles endpoints
    Route::prefix('roles')->group(function () {
        Route::middleware('permission:roles.view')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::get('/{id}', [RoleController::class, 'show'])->whereNumber('id');
        });

        Route::middleware('permission:roles.manage')->group(function () {
            Route::post('/', [RoleController::class, 'store']);
            Route::put('/{id}', [RoleController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [RoleController::class, 'destroy'])->whereNumber('id');

            // Role permissions
            Route::post('{id}/permissions', [RoleController::class, 'assignPermissions'])->whereNumber('id');
        });
    });

    // Permissions endpoints
    Route::prefix('permissions')->group(function () {
        Route::middleware('permission:permissions.view')->group(function () {
            Route::get('/', [PermissionController::class, 'index']);
            Route::get('/{id}', [PermissionController::class, 'show'])->whereNumber('id');
        });

        Route::middleware('permission:permissions.manage')->group(function () {
            Route::post('/', [PermissionController::class, 'store']);
            Route::put('/{id}', [PermissionController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [PermissionController::class, 'destroy'])->whereNumber('id');
        });
    });

    // User role assignment
    Route::prefix('users')->group(function () {
        Route::post('{id}/roles', [RoleController::class, 'assignRole'])
            ->whereNumber('id')
            ->middleware('permission:users.manage');
    });
});
