<?php

namespace Src\Logistics\Order\Services\Pricing;

use Src\Logistics\Order\Services\OrderPricingService;

/**
 * Motor `legacy` (MVP): 5.00 + kg × 2.50 + m³ × 50.00.
 *
 * Se conserva como interruptor de rollback (`PRICING_ENGINE=legacy`) y como
 * referencia de regresión: con él la orden se crea exactamente como antes de la
 * Fase 3, y el desglose lo dice (`engine = legacy`).
 */
final class LegacyPricingEngine
{
    public function __construct(
        private readonly OrderPricingService $pricing,
    ) {}

    public function quote(PricingRequest $request): PricingResult
    {
        $volumeAmount = round($request->volumeM3 * 50.00, 2);
        $total = $this->pricing->calculate([
            'weight_kg' => $request->weightKg,
            'volume_m3' => $request->volumeM3,
        ]);

        return new PricingResult(
            engine: 'legacy',
            chargeableWeightKg: $request->weightKg,
            basePrice: 5.00,
            weightAmount: round($request->weightKg * 2.50, 2),
            minimumAdjustment: 0.0,
            surcharge: 0.0,
            totalAmount: $total,
            tariffId: null,
            tariffCode: null,
            breakdown: [
                'engine' => 'legacy',
                'weight_kg' => $request->weightKg,
                'volume_m3' => $request->volumeM3,
                'base_price' => 5.00,
                'weight_amount' => round($request->weightKg * 2.50, 2),
                'volume_amount' => $volumeAmount,
                'total_amount' => $total,
            ],
        );
    }
}
