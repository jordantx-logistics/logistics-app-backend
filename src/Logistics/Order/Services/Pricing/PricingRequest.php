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

    /**
     * Construye la entrada desde el payload validado de la orden o la
     * cotización (mismos nombres de campo en ambos endpoints).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            originAgencyId: (int) $data['origin_agency_id'],
            destinationAgencyId: (int) $data['destination_agency_id'],
            serviceType: (string) ($data['service_type'] ?? 'standard'),
            weightKg: (float) $data['weight_kg'],
            volumeM3: (float) ($data['volume_m3'] ?? 0),
            homeDelivery: ($data['delivery_type'] ?? 'agency_pickup') === 'home_delivery',
            deliveryLocationId: isset($data['delivery_location_id']) ? (int) $data['delivery_location_id'] : null,
        );
    }
}
