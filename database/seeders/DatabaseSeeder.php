<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
        // Crear roles y permisos por defecto
        $this->call(PermissionSeeder::class);

        // Obtener credenciales de variables de entorno o usar valores por defecto
        $email = env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe');
        $password = env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#');

        // `migrate --seed` tiene que poder repetirse (es el arranque documentado),
        // así que el admin se crea o se actualiza en lugar de insertarse siempre:
        // la contraseña se rota desde el entorno y `email_verified_at` no es
        // asignable en masa (lo pone el cast/fill explícito).
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => $password,
            ],
        );

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
