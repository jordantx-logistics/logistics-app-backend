<?php

namespace Src\Logistics\Order\Services\Pricing;

use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CoverageDirectory;
use Src\Shared\Contracts\LocationDirectory;
use Src\Shared\Contracts\TariffDirectory;
use Src\Shared\DTOs\ResolvedTariff;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Motor `tariff` (Fase 3).
 *
 * Resolución: agencia → ubigeo → ubicación → zona, y el tarifario lo resuelve
 * `TariffDirectory` (más específico primero). Cálculo:
 *
 * 1. **Peso facturable** = max(peso, volumen × factor volumétrico). Si el
 *    tarifario tiene `volumetric_factor = 0`, el volumen se cobra aparte con
 *    `price_per_m3` (tarifa por volumen pura).
 * 2. **Flete por peso**: con tramos, `fixed_fee + (facturable − from_kg) ×
 *    price_per_kg` del tramo que contiene el peso; sin tramos,
 *    `facturable × price_per_kg`.
 * 3. `subtotal = base_price + flete + volumen`.
 * 4. **Ajuste por mínimo**: si `subtotal < min_charge`, se suma la diferencia.
 * 5. **Recargo de reparto**: si la entrega es a domicilio, el de la cobertura
 *    de la agencia destino en esa ubicación; sin cobertura, 422
 *    (`DELIVERY_NOT_COVERED`).
 *
 * Es puro cálculo: no toca HTTP y solo depende de puertos, así que se puede
 * probar con un `PricingRequest` y dobles de los puertos.
 */
final class TariffPricingEngine
{
    public function __construct(
        private readonly TariffDirectory $tariffs,
        private readonly LocationDirectory $locations,
        private readonly AgencyDirectory $agencies,
        private readonly CoverageDirectory $coverages,
    ) {}

    public function quote(PricingRequest $request): PricingResult
    {
        $originUbigeo = $this->agencies->agencyUbigeo($request->originAgencyId);
        $destinationUbigeo = $this->agencies->agencyUbigeo($request->destinationAgencyId);

        $originZoneId = $originUbigeo !== null ? $this->locations->zoneIdForUbigeo($originUbigeo) : null;
        $destinationZoneId = $destinationUbigeo !== null ? $this->locations->zoneIdForUbigeo($destinationUbigeo) : null;
        $originLocationId = $originUbigeo !== null ? $this->locations->locationIdForUbigeo($originUbigeo) : null;
        $destinationLocationId = $destinationUbigeo !== null ? $this->locations->locationIdForUbigeo($destinationUbigeo) : null;

        $tariff = $this->tariffs->findForRoute(
            $request->serviceType,
            $originZoneId,
            $destinationZoneId,
            $originLocationId,
            $destinationLocationId,
        );

        if ($tariff === null) {
            throw new BusinessRuleException(
                message: "There is no valid tariff for {$request->serviceType} service on this route.",
                errorCode: 'TARIFF_NOT_FOUND',
            );
        }

        $volumetricWeight = $tariff->volumetricFactor > 0
            ? round($request->volumeM3 * $tariff->volumetricFactor, 3)
            : 0.0;

        $chargeableWeight = round(max($request->weightKg, $volumetricWeight), 3);

        if ($chargeableWeight > $tariff->maxWeightKg) {
            throw new BusinessRuleException(
                message: "The chargeable weight ({$chargeableWeight} kg) exceeds the tariff maximum of {$tariff->maxWeightKg} kg.",
                errorCode: 'WEIGHT_EXCEEDED',
            );
        }

        [$bracket, $weightAmount] = $this->weightAmount($tariff, $chargeableWeight);

        $volumeAmount = $tariff->volumetricFactor === 0
            ? round($request->volumeM3 * $tariff->pricePerM3, 2)
            : 0.0;

        $subtotal = round($tariff->basePrice + $weightAmount + $volumeAmount, 2);
        $minimumAdjustment = round(max(0, $tariff->minCharge - $subtotal), 2);
        $surcharge = $this->surcharge($request, $destinationLocationId);

        $total = round($subtotal + $minimumAdjustment + $surcharge, 2);

        return new PricingResult(
            engine: 'tariff',
            chargeableWeightKg: $chargeableWeight,
            basePrice: $tariff->basePrice,
            weightAmount: $weightAmount,
            minimumAdjustment: $minimumAdjustment,
            surcharge: $surcharge,
            totalAmount: $total,
            tariffId: $tariff->id,
            tariffCode: $tariff->code,
            breakdown: [
                'engine' => 'tariff',
                'tariff_id' => $tariff->id,
                'tariff_code' => $tariff->code,
                'tariff_name' => $tariff->name,
                'scope_type' => $tariff->scopeType,
                'service_type' => $tariff->serviceType,
                'origin_zone_id' => $originZoneId,
                'destination_zone_id' => $destinationZoneId,
                'volumetric_factor' => $tariff->volumetricFactor,
                'volumetric_weight_kg' => $volumetricWeight,
                'chargeable_weight_kg' => $chargeableWeight,
                'base_price' => $tariff->basePrice,
                'weight_amount' => $weightAmount,
                'volume_amount' => $volumeAmount,
                'minimum_adjustment' => $minimumAdjustment,
                'surcharge' => $surcharge,
                'total_amount' => $total,
                'bracket' => $bracket,
            ],
        );
    }

    /**
     * Flete por peso y tramo aplicado (o `null` si el tarifario no tiene tramos).
     *
     * @return array{0: array{from_kg: float, to_kg: float, fixed_fee: float, price_per_kg: float}|null, 1: float}
     */
    private function weightAmount(ResolvedTariff $tariff, float $chargeableWeight): array
    {
        if ($tariff->brackets === []) {
            return [null, round($chargeableWeight * $tariff->pricePerKg, 2)];
        }

        // Último tramo cuyo `from_kg` no supera el peso; si el peso queda por
        // debajo del primero, se usa el primero (su `fixed_fee` es el mínimo).
        $bracket = $tariff->brackets[0];

        foreach ($tariff->brackets as $candidate) {
            if ($chargeableWeight >= $candidate['from_kg']) {
                $bracket = $candidate;
            }
        }

        $amount = $bracket['fixed_fee']
            + max(0.0, $chargeableWeight - $bracket['from_kg']) * $bracket['price_per_kg'];

        return [$bracket, round($amount, 2)];
    }

    /** Recargo de reparto a domicilio (0 si la entrega es en agencia). */
    private function surcharge(PricingRequest $request, ?int $destinationLocationId): float
    {
        if (! $request->homeDelivery) {
            return 0.0;
        }

        $locationId = $request->deliveryLocationId ?? $destinationLocationId;
        $surcharge = $locationId !== null
            ? $this->coverages->deliverySurcharge($request->destinationAgencyId, $locationId)
            : null;

        if ($surcharge === null) {
            throw new BusinessRuleException(
                message: 'The destination agency does not offer home delivery at that location.',
                errorCode: 'DELIVERY_NOT_COVERED',
            );
        }

        return $surcharge;
    }
}
