<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;
use Src\Shared\Services\ProcessedEvents;

/**
 * Consume `delivery.completed` y cierra la orden como entregada.
 *
 * Operaciones publica un solo evento (`delivery.completed`); Core es quien
 * actualiza su estado comercial. Si la orden ya está `delivered` o `cancelled`
 * el evento se ignora. Un estado distinto de `in_transit` se registra como
 * anomalía: la entrega no debería llegar antes que `shipment.embarked`.
 */
final class MarkOrderDeliveredJob implements ShouldQueue
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

            if ($order === null) {
                return;
            }

            if (in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], true)) {
                return;
            }

            if ($order->status !== Order::STATUS_IN_TRANSIT) {
                Log::warning('order.delivery_out_of_order', [
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'delivery_id' => $this->payload['delivery_id'] ?? null,
                ]);

                return;
            }

            $machine->transition(
                order: $order,
                to: Order::STATUS_DELIVERED,
                reason: 'delivery.completed',
            );
        });
    }
}
