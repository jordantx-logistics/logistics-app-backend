<?php

namespace Database\Factories\Src\Identity\Role\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Identity\Role\Models\Permission;

class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            // Ver `RoleFactory`: el guard de la aplicación es `web`, no `api`.
            'guard_name' => 'web',
        ];
    }
}
