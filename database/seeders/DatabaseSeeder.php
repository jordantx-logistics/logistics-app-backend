<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Las credenciales se obtienen de las variables de entorno:
     * - SCRIBE_AUTH_EMAIL
     * - SCRIBE_AUTH_PASSWORD
     *
     * Si no están configuradas, se usan valores por defecto.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Crear roles y permisos por defecto
        $this->call(PermissionSeeder::class);

        // Obtener credenciales de variables de entorno o usar valores por defecto
        $email = env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe');
        $password = env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#');

        User::factory()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }
}
