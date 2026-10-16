<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Rules\CheckDuplicateOrder;
use Src\Logistics\Order\Rules\ValidateAgencyExists;
use Src\Logistics\Order\Rules\ValidateCustomerExists;
use Src\Logistics\Order\Rules\ValidateOrderWeight;
use Src\Logistics\Order\Services\OrderPricingService;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Pipelines\RulesPipeline;

class CreateOrderUseCase
{
    public function __construct(
        private readonly OrderPricingService $pricingService,
    ) {}

    public function execute(array $data): Order
    {
        // 1: Business rule validations via Pipeline
        $data = RulesPipeline::run($data, [
            CheckDuplicateOrder::class,
            ValidateCustomerExists::class,
            ValidateAgencyExists::class,
            ValidateOrderWeight::class,
        ]);

        // 2: Pricing Logic
        $data['total_amount'] = $this->pricingService->calculate($data);

        // 3: Transactional core: Save + Outbox
        return DB::transaction(function () use ($data) {
            $order = Order::create([
                'idempotency_key' => $data['idempotency_key'],
                'sender_id' => $data['sender_id'],
                'receiver_id' => $data['receiver_id'],
                'origin_agency_id' => $data['origin_agency_id'],
                'destination_agency_id' => $data['destination_agency_id'],
                'description' => $data['description'] ?? null,
                'weight_kg' => $data['weight_kg'],
                'volume_m3' => $data['volume_m3'] ?? null,
                'declared_value' => $data['declared_value'] ?? 0,
                'total_amount' => $data['total_amount'],
                'status' => 'pending',
                'created_by' => auth()->id(),
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
