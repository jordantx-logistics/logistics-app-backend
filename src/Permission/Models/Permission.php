<?php

namespace Src\Permission\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Model para representar un permiso del sistema.
 *
 * Extiende el modelo de spatie/laravel-permission para heredar el
 * `guard_name` por defecto, las relaciones `roles`/`users` y la
 * invalidación de caché del paquete.
 */
class Permission extends SpatiePermission
{
    use HasFactory;
}
