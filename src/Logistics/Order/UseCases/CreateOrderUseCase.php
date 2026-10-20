<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Models\OrderStatusHistory;
use Src\Logistics\Order\Rules\CheckDuplicateOrder;
use Src\Logistics\Order\Rules\ValidateAgencyExists;
use Src\Logistics\Order\Rules\ValidateCustomerExists;
use Src\Logistics\Order\Rules\ValidateDistinctAgencies;
use Src\Logistics\Order\Rules\ValidateOrderWeight;
use Src\Logistics\Order\Services\Pricing\PricingRequest;
use Src\Logistics\Order\Services\Pricing\PricingService;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta de orden.
 *
 * El precio lo pone el motor configurado (`PricingService`): tarifarios de la
 * Fase 3 o legacy. Se guarda el **desglose** (`pricing_snapshot`) y el peso
 * facturable, no solo el total, para poder auditar el precio después.
 *
 * La orden nace `pending` y su primera fila de `order_status_history` se escribe
 * en la misma transacción, junto al evento de outbox.
 */
class CreateOrderUseCase
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    public function execute(array $data): Order
    {
        // 1: Business rule validations via Pipeline
        $data = RulesPipeline::run($data, [
            CheckDuplicateOrder::class,
            ValidateCustomerExists::class,
            ValidateAgencyExists::class,
            ValidateDistinctAgencies::class,
            ValidateOrderWeight::class,
        ]);

        // 2: Pricing (tariff o legacy según `PRICING_ENGINE`)
        $result = $this->pricing->quote(PricingRequest::fromArray($data));

        // 3: Transactional core: Save + History + Outbox
        return DB::transaction(function () use ($data, $result) {
            $order = Order::create([
                'idempotency_key' => $data['idempotency_key'],
                'sender_id' => $data['sender_id'],
                'receiver_id' => $data['receiver_id'],
                'origin_agency_id' => $data['origin_agency_id'],
                'destination_agency_id' => $data['destination_agency_id'],
                'description' => $data['description'] ?? null,
                'weight_kg' => $data['weight_kg'],
                'volume_m3' => $data['volume_m3'] ?? null,
                'chargeable_weight_kg' => $result->chargeableWeightKg,
                'service_type' => $data['service_type'] ?? Order::SERVICE_STANDARD,
                'payment_responsibility' => $data['payment_responsibility'] ?? Order::PAYMENT_SENDER,
                'tariff_id' => $result->tariffId,
                'pricing_snapshot' => $result->breakdown,
                'declared_value' => $data['declared_value'] ?? 0,
                'total_amount' => $result->totalAmount,
                'status' => Order::STATUS_PENDING,
                'delivery_type' => $data['delivery_type'] ?? Order::DELIVERY_AGENCY_PICKUP,
                'delivery_location_id' => $data['delivery_location_id'] ?? null,
                'recipient_address' => $data['recipient_address'] ?? null,
                'delivery_scheduled_at' => $data['delivery_scheduled_at'] ?? null,
                'requires_signature' => $data['requires_signature'] ?? false,
                'created_by' => auth()->id(),
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => Order::STATUS_PENDING,
                'changed_by' => auth()->id(),
            ]);

            OutboxEvent::create([
                'event_type' => 'order.created',
                'payload' => [
                    'order_id' => $order->id,
                    'ticket_number' => $order->ticket_number,
                    'ticket_code' => $order->ticket_code,
                    'total_amount' => $order->total_amount,
                    'created_by' => $order->created_by,
                ],
            ]);

            return $order;
        });
    }
}
