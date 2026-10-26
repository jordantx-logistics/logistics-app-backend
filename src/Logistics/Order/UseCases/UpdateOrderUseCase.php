<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Rules\ValidateOrderWeight;
use Src\Logistics\Order\Services\Pricing\PricingRequest;
use Src\Logistics\Order\Services\Pricing\PricingService;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Edición de una orden `pending` o `confirmed` (cierre de Fase 3).
 *
 * Se pueden tocar los datos de envío y de reparto, no la identidad comercial:
 * remitente, destinatario, agencias, servicio y tarifa quedan fijos. Si cambia
 * el peso, el volumen o el reparto, se vuelve a cotizar y se actualiza el
 * snapshot; si solo cambia la descripción o el valor declarado, el precio no se
 * toca.
 *
 * No escribe `order.updated`: no hay consumidor todavía y el outbox marcaría el
 * evento como `failed`. Se añadirá cuando exista uno (misma decisión que
 * `customer.*` y `tariff.updated` en Fase 3).
 */
class UpdateOrderUseCase
{
    /** Campos que obligan a recalcular el precio. */
    private const PRICING_FIELDS = ['weight_kg', 'volume_m3', 'delivery_type', 'delivery_location_id'];

    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Order $order, array $data): Order
    {
        if ($data === []) {
            throw new BusinessRuleException(
                message: 'No updatable fields were provided.',
                errorCode: 'NO_UPDATE_DATA',
            );
        }

        return DB::transaction(function () use ($order, $data): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [Order::STATUS_PENDING, Order::STATUS_CONFIRMED], true)) {
                throw new BusinessRuleException(
                    message: 'The order cannot be edited after it left the origin agency.',
                    errorCode: 'ORDER_NOT_EDITABLE',
                );
            }

            $pricingChanged = array_intersect(array_keys($data), self::PRICING_FIELDS) !== [];

            $deliveryType = $data['delivery_type'] ?? $locked->delivery_type;
            $deliveryLocationId = $deliveryType === Order::DELIVERY_HOME
                ? ($data['delivery_location_id'] ?? $locked->delivery_location_id)
                : null;

            $attributes = [];

            foreach (['description', 'weight_kg', 'volume_m3', 'declared_value', 'delivery_type', 'delivery_scheduled_at', 'requires_signature'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if ($deliveryType === Order::DELIVERY_HOME) {
                if (array_key_exists('delivery_location_id', $data)) {
                    $attributes['delivery_location_id'] = $data['delivery_location_id'];
                }

                if (array_key_exists('recipient_address', $data)) {
                    $attributes['recipient_address'] = $data['recipient_address'];
                }
            } else {
                // Entrega en agencia: no hay ubicación de reparto.
                $attributes['delivery_location_id'] = null;

                if (array_key_exists('recipient_address', $data)) {
                    $attributes['recipient_address'] = $data['recipient_address'];
                }
            }

            if ($pricingChanged) {
                RulesPipeline::run(
                    ['weight_kg' => (float) ($data['weight_kg'] ?? $locked->weight_kg)],
                    [ValidateOrderWeight::class],
                );

                $result = $this->pricing->quote(PricingRequest::fromArray([
                    'origin_agency_id' => $locked->origin_agency_id,
                    'destination_agency_id' => $locked->destination_agency_id,
                    'service_type' => $locked->service_type,
                    'weight_kg' => (float) ($data['weight_kg'] ?? $locked->weight_kg),
                    'volume_m3' => (float) ($data['volume_m3'] ?? $locked->volume_m3 ?? 0),
                    'delivery_type' => $deliveryType,
                    'delivery_location_id' => $deliveryLocationId,
                ]));

                $attributes['tariff_id'] = $result->tariffId;
                $attributes['pricing_snapshot'] = $result->breakdown;
                $attributes['chargeable_weight_kg'] = $result->chargeableWeightKg;
                $attributes['total_amount'] = $result->totalAmount;
                $attributes['delivery_type'] = $deliveryType;
                $attributes['delivery_location_id'] = $deliveryLocationId;
            }

            $attributes['version'] = $locked->version + 1;

            $locked->update($attributes);

            return $locked->refresh();
        });
    }
}
