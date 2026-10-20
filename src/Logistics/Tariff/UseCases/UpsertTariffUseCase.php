<?php

namespace Src\Logistics\Tariff\UseCases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Logistics\Tariff\Models\TariffBracket;
use Src\Logistics\Tariff\Rules\ValidateTariffDefinition;
use Src\Logistics\Tariff\Rules\ValidateTariffOverlap;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta y edición de tarifarios (con sus tramos).
 *
 * Un mismo caso de uso para ambos: las invariantes y el reemplazo de tramos son
 * idénticos. En la edición el payload se **funde con el tarifario actual** antes
 * de validar, para que cambiar `scope_type` sin repetir los ids no deje un
 * tarifario con el par viejo pegado.
 *
 * Al terminar invalida la caché por tag (`tariffs`): el adaptador
 * `EloquentTariffDirectory` cachea el tarifario resuelto por ruta y servicio.
 */
class UpsertTariffUseCase
{
    /** Campos que se copian tal cual del payload al modelo. */
    private const FIELDS = [
        'code', 'name', 'service_type', 'scope_type',
        'origin_zone_id', 'destination_zone_id', 'origin_location_id', 'destination_location_id',
        'base_price', 'price_per_kg', 'price_per_m3', 'min_charge', 'max_weight_kg',
        'volumetric_factor', 'priority', 'valid_from', 'valid_to', 'is_active',
    ];

    public function execute(array $data, ?Tariff $tariff = null, ?int $createdBy = null): Tariff
    {
        $data = array_merge($this->currentValues($tariff), $data, ['tariff_id' => $tariff?->id]);

        $data = RulesPipeline::run($data, [
            ValidateTariffDefinition::class,
            ValidateTariffOverlap::class,
        ]);

        $tariff = DB::transaction(function () use ($data, $tariff, $createdBy): Tariff {
            $attributes = array_intersect_key($data, array_flip(self::FIELDS));

            if ($tariff === null) {
                $tariff = Tariff::create([...$attributes, 'created_by' => $createdBy]);
            } else {
                $tariff->update($attributes);

                // Los tramos se reemplazan en bloque: son parte del tarifario y
                // no tienen identidad propia para el cliente.
                TariffBracket::query()->where('tariff_id', $tariff->id)->delete();
            }

            foreach ($data['brackets'] ?? [] as $bracket) {
                $tariff->brackets()->create($bracket);
            }

            return $tariff->refresh();
        });

        Cache::tags(['tariffs'])->flush();

        return $tariff;
    }

    /**
     * Valores actuales del tarifario, con las fechas y los tramos en el mismo
     * formato que el payload validado.
     *
     * @return array<string, mixed>
     */
    private function currentValues(?Tariff $tariff): array
    {
        if ($tariff === null) {
            return [];
        }

        return [
            ...$tariff->only(self::FIELDS),
            'valid_from' => $tariff->valid_from?->toDateString(),
            'valid_to' => $tariff->valid_to?->toDateString(),
            'brackets' => $tariff->brackets->map(fn (TariffBracket $bracket): array => [
                'from_kg' => (float) $bracket->from_kg,
                'to_kg' => (float) $bracket->to_kg,
                'fixed_fee' => (float) $bracket->fixed_fee,
                'price_per_kg' => (float) $bracket->price_per_kg,
            ])->all(),
        ];
    }
}
