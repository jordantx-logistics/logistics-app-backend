<?php

namespace Src\Logistics\Order\Services\Pricing;

/**
 * Entrada del motor de precios.
 *
 * Es un objeto plano (sin Eloquent, sin HTTP) para que el cálculo sea
 * determinista y testeable: el motor solo recibe ids y medidas, y resuelve lo
 * demás por puertos.
 */
final class PricingRequest
{
    public function __construct(
        public readonly int $originAgencyId,
        public readonly int $destinationAgencyId,
        public readonly string $serviceType,
        public readonly float $weightKg,
        public readonly float $volumeM3,
        public readonly bool $homeDelivery = false,
        public readonly ?int $deliveryLocationId = null,
    ) {}
}
