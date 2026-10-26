<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;
use Src\Shared\Models\OutboxEvent;

/**
 * Cancelación de la orden (`pending` o `confirmed` → `cancelled`).
 *
 * El motivo es opcional pero se guarda en `cancelled_reason` y en el histórico:
 * es lo que permite responder por qué se cayó una orden sin abrir los logs.
 *
 * La transición y `order.cancelled` van en la misma transacción. Operaciones
 * consume este evento para cancelar el envío si aún no salió a reparto.
 */
class CancelOrderUseCase
{
    public function __construct(
        private readonly OrderStatusMachine $machine,
    ) {}

    public function execute(Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason): Order {
            $cancelled = $this->machine->transition($order, Order::STATUS_CANCELLED, $reason);

            OutboxEvent::create([
                'event_type' => 'order.cancelled',
                'payload' => [
                    'order_id' => $cancelled->id,
                    'ticket_number' => $cancelled->ticket_number,
                    'ticket_code' => $cancelled->ticket_code,
                    'status' => $cancelled->status,
                    'origin_agency_id' => $cancelled->origin_agency_id,
                    'destination_agency_id' => $cancelled->destination_agency_id,
                    'reason' => $reason,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => $cancelled->cancelled_at?->toIso8601String(),
                ],
            ]);

            return $cancelled;
        });
    }
}
