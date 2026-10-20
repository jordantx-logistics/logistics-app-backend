<?php

namespace Src\Logistics\Order\UseCases;

use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\Pricing\PricingRequest;
use Src\Logistics\Order\Services\Pricing\PricingService;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Define el reparto de una orden que todavía no está confirmada (UC-08).
 *
 * El reparto es un **atributo** de la orden, pero cambia el precio (recargo de
 * reparto de la cobertura de la agencia destino), así que solo se puede tocar
 * mientras la orden está `pending`. Tras confirmar, el snapshot queda congelado
 * y la respuesta es `ORDER_ALREADY_CONFIRMED` (409).
 *
 * Si no hay cobertura de reparto en esa ubicación, el motor lanza
 * `DELIVERY_NOT_COVERED` (422).
 */
class UpdateOrderDeliveryUseCase
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    public function execute(Order $order, array $data): Order
    {
        if ($order->status !== Order::STATUS_PENDING) {
            throw new BusinessRuleException(
                message: 'The order is not pending: its price and delivery cannot change after confirmation.',
                errorCode: 'ORDER_ALREADY_CONFIRMED',
                statusCode: 409,
            );
        }

        $result = $this->pricing->quote(PricingRequest::fromArray([
            'origin_agency_id' => $order->origin_agency_id,
            'destination_agency_id' => $order->destination_agency_id,
            'service_type' => $order->service_type,
            'weight_kg' => (float) $order->weight_kg,
            'volume_m3' => (float) $order->volume_m3,
            'delivery_type' => $data['delivery_type'],
            'delivery_location_id' => $data['delivery_location_id'] ?? null,
        ]));

        $order->update([
            'delivery_type' => $data['delivery_type'],
            'delivery_location_id' => $data['delivery_location_id'] ?? null,
            'recipient_address' => $data['recipient_address'] ?? $order->recipient_address,
            'delivery_scheduled_at' => $data['delivery_scheduled_at'] ?? $order->delivery_scheduled_at,
            'requires_signature' => $data['requires_signature'] ?? $order->requires_signature,
            'tariff_id' => $result->tariffId,
            'pricing_snapshot' => $result->breakdown,
            'chargeable_weight_kg' => $result->chargeableWeightKg,
            'total_amount' => $result->totalAmount,
            'version' => $order->version + 1,
        ]);

        return $order->refresh();
    }
}
