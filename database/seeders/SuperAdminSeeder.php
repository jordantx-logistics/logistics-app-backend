<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeder para crear un usuario con rol SuperAdmin
 *
 * Este seeder crea:
 * - Un usuario administrador con credenciales de variables de entorno
 * - El rol SuperAdmin con todos los permisos disponibles
 *
 * Credenciales (variables de entorno):
 * - SCRIBE_AUTH_EMAIL
 * - SCRIBE_AUTH_PASSWORD
 *
 * Si no están configuradas, se usan valores por defecto.
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener credenciales de variables de entorno o usar valores por defecto
        $email = env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe');
        $password = env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#');

        // Crear rol SuperAdmin si no existe
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'SuperAdmin'],
            ['guard_name' => 'api']
        );

        // Asignar todos los permisos existentes al rol SuperAdmin
        $permissions = Permission::all();
        $superAdminRole->givePermissionTo($permissions);

        // Crear usuario administrador si no existe
        $adminUser = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ]
        );

        // Asignar rol SuperAdmin al usuario
        $adminUser->assignRole($superAdminRole);

        // Log de creación
        $this->command->info('Usuario SuperAdmin creado/actualizado');
        $this->command->info('Email: '.$email);
        $this->command->info('Password: '.$password);
        $this->command->info('Rol: SuperAdmin (todos los permisos)');
    }
}
