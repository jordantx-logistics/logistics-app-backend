<?php

namespace Src\Permission\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Model para representar un rol del sistema.
 *
 * Extiende el modelo de spatie/laravel-permission para heredar el
 * `guard_name` por defecto, las relaciones `permissions`/`users` y la
 * invalidación de caché del paquete.
 */
class Role extends SpatieRole
{
    use HasFactory;
}
