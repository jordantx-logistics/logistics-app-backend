<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;
use Src\Shared\Services\ProcessedEvents;

/**
 * Consume `shipment.embarked` y pasa la orden de `confirmed` a `in_transit`.
 *
 * Si la orden ya está `in_transit` o en un estado posterior, no hace nada:
 * el outbox es at-least-once y los eventos pueden repetirse.
 */
final class MarkOrderInTransitJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProcessedEvents $processed, OrderStatusMachine $machine): void
    {
        $processed->once($this->outboxEventId, self::class, function () use ($machine): void {
            $order = Order::query()->lockForUpdate()->find($this->payload['order_id'] ?? 0);

            if ($order === null || $order->status !== Order::STATUS_CONFIRMED) {
                return;
            }

            $machine->transition(
                order: $order,
                to: Order::STATUS_IN_TRANSIT,
                reason: 'shipment.embarked',
            );
        });
    }
}
