<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Src\Finance\Invoice\Models\InvoiceSeries;
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

        // Series fiscales de demostración: los datos reales se cargan por el
        // endpoint admin `invoice-series` o el comando de configuración.
        $agency = Agency::query()->where('code', 'LIM-01')->first();

        if ($agency !== null) {
            foreach (['boleta' => 'B001', 'factura' => 'F001'] as $documentType => $series) {
                InvoiceSeries::updateOrCreate(
                    [
                        'agency_id' => $agency->id,
                        'document_type' => $documentType,
                        'series' => $series,
                    ],
                    [
                        'issuer_ruc' => '20123456789',
                        'issuer_name' => 'Logistics Demo SAC',
                        'issuer_trade_name' => 'Logistics Demo',
                        'issuer_address' => 'Av. Demo 123, Lima',
                        'issuer_ubigeo' => '150101',
                        'establishment_code' => '0000',
                        'is_active' => true,
                        'is_default' => true,
                    ],
                );
            }
        }
    }
}
