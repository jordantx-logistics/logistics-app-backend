<?php

namespace Src\Identity\Role\Models;

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

    /**
     * Guard por defecto de los roles (`web`, el de Sanctum/la sesión).
     *
     * Sin esta propiedad, `Role::create()` deduce el guard de
     * `config('auth.defaults.guard')`, que dentro de una petición vale `sanctum`
     * (lo cambia el middleware `auth:sanctum`): el rol nacería con un guard que
     * no casa con el de los usuarios.
     *
     * @var string
     */
    protected $guard_name = 'web';
}
