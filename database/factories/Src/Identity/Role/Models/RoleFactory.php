<?php

namespace Database\Factories\Src\Identity\Role\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Identity\Role\Models\Role;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            // El guard real de la aplicación es `web` (`config/auth.php` y
            // `$guard_name` de los modelos de `Src\Identity\Role`). El ejemplo de
            // Scribe sale de esta factory: con `api` documentaba un guard que
            // ya no existe.
            'guard_name' => 'web',
        ];
    }
}
