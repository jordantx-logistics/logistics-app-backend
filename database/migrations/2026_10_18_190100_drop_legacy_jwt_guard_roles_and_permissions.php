<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Elimina el catálogo legado del guard `api` (JWT).
 *
 * Con Sanctum el guard es `sanctum` para los tokens y `web` para la sesión, y
 * Spatie guarda roles y permisos por `guard_name`. Las filas sembradas con
 * `api` quedarían huérfanas: ni las resuelve Spatie (guard inexistente) ni
 * desaparecen solas. Los pivotes (`role_has_permissions`, `model_has_roles`)
 * caen en cascada. El catálogo nuevo lo crea `PermissionSeeder`, idempotente.
 *
 * En una base de datos nueva (tests) no hay nada que borrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('guard_name', 'api')->delete();
        DB::table('roles')->where('guard_name', 'api')->delete();
    }

    public function down(): void
    {
        // Sin vuelta atrás: el catálogo nuevo lo recrea el seeder.
    }
};
