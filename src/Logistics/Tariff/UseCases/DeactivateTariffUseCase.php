<?php

namespace Src\Logistics\Tariff\UseCases;

use Illuminate\Support\Facades\Cache;
use Src\Logistics\Tariff\Models\Tariff;

/**
 * Baja de tarifario: se desactiva, no se borra.
 *
 * Un tarifario puede estar referenciado por el desglose de una orden
 * (`pricing_breakdown`), así que la fila se conserva para poder auditar el
 * precio aplicado. Al desactivarlo se invalida la caché de tarifas resueltas.
 */
class DeactivateTariffUseCase
{
    public function execute(Tariff $tariff): Tariff
    {
        $tariff->update(['is_active' => false]);

        Cache::tags(['tariffs'])->flush();

        return $tariff->refresh();
    }
}
