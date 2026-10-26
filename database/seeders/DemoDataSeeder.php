<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Src\Organization\Models\Agency;

/**
 * Datos de demo para desarrollo local.
 *
 * `migrate --seed` no debe inventar agencias en producción: este seeder solo
 * corre en `local` y `testing`. Deja una agencia con ubigeo para que el motor de
 * tarifarios tenga una ruta resoluble y completa el ubigeo de las agencias de
 * demo que se crearon antes de que existiera el campo.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        Agency::query()->whereNull('ubigeo')->update(['ubigeo' => '150101']);

        Agency::updateOrCreate(
            ['code' => 'LIM-01'],
            [
                'name' => 'Lima Centro',
                'type' => 'branch',
                'ubigeo' => '150101',
                'timezone' => 'America/Lima',
                'is_active' => true,
            ],
        );
    }
}
