<?php

namespace Src\Logistics\Order\Services\Pricing;

/**
 * Resultado del motor de precios, con el desglose que se guarda en la orden.
 *
 * `breakdown` es lo que persiste `orders.pricing_breakdown` (JSON): tarifario
 * aplicado, peso volumétrico, tramo, ajuste por mínimo y recargo de reparto.
 * Se guarda el desglose y no solo el total para poder auditar un precio aunque
 * el tarifario cambie después.
 */
final class PricingResult
{
    /**
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public readonly string $engine,
        public readonly float $chargeableWeightKg,
        public readonly float $basePrice,
        public readonly float $weightAmount,
        public readonly float $minimumAdjustment,
        public readonly float $surcharge,
        public readonly float $totalAmount,
        public readonly ?int $tariffId,
        public readonly ?string $tariffCode,
        public readonly array $breakdown,
    ) {}
}
