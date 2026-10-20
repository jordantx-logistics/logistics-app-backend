<?php

namespace Src\Logistics\Tariff\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Shared\Contracts\TariffDirectory;
use Src\Shared\DTOs\ResolvedTariff;

/**
 * Adaptador Eloquent + caché del puerto `TariffDirectory`.
 *
 * Orden de resolución: gana el alcance más específico (`district_pair` >
 * `zone_pair` > `national`), luego el `priority` más alto y, a igualdad, el
 * tarifario más reciente. Solo entran tarifarios activos y vigentes hoy.
 *
 * El resultado se cachea en Redis bajo el tag `tariffs` (`Cache::tags`), que
 * `UpsertTariff` invalida al crear, editar o desactivar: la clave es
 * ruta + servicio, y no hace falta invalidar claves concretas.
 */
class EloquentTariffDirectory implements TariffDirectory
{
    /** Segundos que vive un tarifario resuelto (se invalida al editarlo). */
    private const CACHE_TTL = 3600;

    public function findForRoute(
        string $serviceType,
        ?int $originZoneId,
        ?int $destinationZoneId,
        ?int $originLocationId,
        ?int $destinationLocationId,
    ): ?ResolvedTariff {
        $key = sprintf(
            'tariff:%s:%s:%s:%s:%s',
            $serviceType,
            $originZoneId ?? '-',
            $destinationZoneId ?? '-',
            $originLocationId ?? '-',
            $destinationLocationId ?? '-',
        );

        return Cache::tags(['tariffs'])->remember(
            $key,
            self::CACHE_TTL,
            fn (): ?ResolvedTariff => $this->resolve($serviceType, $originZoneId, $destinationZoneId, $originLocationId, $destinationLocationId),
        );
    }

    private function resolve(
        string $serviceType,
        ?int $originZoneId,
        ?int $destinationZoneId,
        ?int $originLocationId,
        ?int $destinationLocationId,
    ): ?ResolvedTariff {
        $tariff = Tariff::query()
            ->with('brackets')
            ->active()
            ->currentlyValid()
            ->where('service_type', $serviceType)
            ->where(function (Builder $query) use ($originZoneId, $destinationZoneId, $originLocationId, $destinationLocationId): void {
                $query->where('scope_type', Tariff::SCOPE_NATIONAL);

                // Sin zona (agencia sin ubigeo) no hay par que comparar: esas
                // ramas no se añaden y solo queda la tarifa nacional.
                if ($originZoneId !== null && $destinationZoneId !== null) {
                    $query->orWhere(fn (Builder $pair) => $pair
                        ->where('scope_type', Tariff::SCOPE_ZONE_PAIR)
                        ->where('origin_zone_id', $originZoneId)
                        ->where('destination_zone_id', $destinationZoneId));
                }

                if ($originLocationId !== null && $destinationLocationId !== null) {
                    $query->orWhere(fn (Builder $pair) => $pair
                        ->where('scope_type', Tariff::SCOPE_DISTRICT_PAIR)
                        ->where('origin_location_id', $originLocationId)
                        ->where('destination_location_id', $destinationLocationId));
                }
            })
            ->orderByRaw("CASE scope_type WHEN 'district_pair' THEN 3 WHEN 'zone_pair' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();

        if ($tariff === null) {
            return null;
        }

        return new ResolvedTariff(
            id: $tariff->id,
            code: $tariff->code,
            name: $tariff->name,
            serviceType: $tariff->service_type,
            scopeType: $tariff->scope_type,
            basePrice: (float) $tariff->base_price,
            pricePerKg: (float) $tariff->price_per_kg,
            pricePerM3: (float) $tariff->price_per_m3,
            minCharge: (float) $tariff->min_charge,
            maxWeightKg: (float) $tariff->max_weight_kg,
            volumetricFactor: (int) $tariff->volumetric_factor,
            brackets: $tariff->brackets->map(fn ($bracket): array => [
                'from_kg' => (float) $bracket->from_kg,
                'to_kg' => (float) $bracket->to_kg,
                'fixed_fee' => (float) $bracket->fixed_fee,
                'price_per_kg' => (float) $bracket->price_per_kg,
            ])->all(),
        );
    }
}
