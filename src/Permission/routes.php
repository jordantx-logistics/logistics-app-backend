<?php

use Illuminate\Support\Facades\Route;
use Src\Permission\Controllers\PermissionController;
use Src\Permission\Controllers\RoleController;

Route::prefix('api/v1/permission')->middleware('auth:api')->group(function () {
    // `whereNumber` no es cosmético: los controladores reciben `int $id`, así que
    // un id no numérico produciría un TypeError (500). Con la restricción la URL
    // no casa con ninguna ruta y la respuesta es 404, como un id inexistente.
    //
    // Roles endpoints
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/', [RoleController::class, 'store']);
        Route::get('/{id}', [RoleController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [RoleController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->whereNumber('id');

        // Role permissions
        Route::prefix('{id}')->whereNumber('id')->group(function () {
            Route::post('permissions', [RoleController::class, 'assignPermissions']);
        });
    });

    // Permissions endpoints
    Route::prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index']);
        Route::post('/', [PermissionController::class, 'store']);
        Route::get('/{id}', [PermissionController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [PermissionController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PermissionController::class, 'destroy'])->whereNumber('id');
    });

    // User role assignment
    Route::prefix('users')->group(function () {
        Route::prefix('{id}')->whereNumber('id')->group(function () {
            Route::post('roles', [RoleController::class, 'assignRole']);
        });
    });
});
