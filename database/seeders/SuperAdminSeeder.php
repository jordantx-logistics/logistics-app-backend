<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea (o actualiza) el usuario administrador del entorno y le asigna el rol
 * `admin` del catálogo de `PermissionSeeder`.
 *
 * Es idempotente y se puede repetir: `migrate --seed` es el arranque
 * documentado. `DatabaseSeeder` hace lo mismo tras sembrar el catálogo; este
 * seeder suelto existe para poder provisionar el admin sin tocar el resto.
 *
 * Credenciales (variables de entorno):
 * - SCRIBE_AUTH_EMAIL
 * - SCRIBE_AUTH_PASSWORD
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe');
        $password = env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#');

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

        $user->assignRole('admin');

        $this->command->info('Usuario admin creado/actualizado');
        $this->command->info('Email: '.$email);
    }
}
