<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;
use Src\Shared\Models\OutboxEvent;

/**
 * Confirmación de la orden (`pending` → `confirmed`).
 *
 * A partir de aquí el precio queda congelado: el snapshot ya está guardado y las
 * ediciones de reparto que lo cambiarían se rechazan
 * (`UpdateOrderDeliveryUseCase`).
 *
 * La transición y el evento `order.confirmed` se escriben en la misma
 * transacción: si falla cualquiera de los dos, no queda ni el estado ni el
 * evento a medias.
 */
class ConfirmOrderUseCase
{
    public function __construct(
        private readonly OrderStatusMachine $machine,
    ) {}

    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $confirmed = $this->machine->transition($order, Order::STATUS_CONFIRMED);

            OutboxEvent::create([
                'event_type' => 'order.confirmed',
                'payload' => [
                    'order_id' => $confirmed->id,
                    'ticket_number' => $confirmed->ticket_number,
                    'ticket_code' => $confirmed->ticket_code,
                    'status' => $confirmed->status,
                    'origin_agency_id' => $confirmed->origin_agency_id,
                    'destination_agency_id' => $confirmed->destination_agency_id,
                    'confirmed_by' => auth()->id(),
                    'confirmed_at' => $confirmed->confirmed_at?->toIso8601String(),
                ],
            ]);

            return $confirmed;
        });
    }
}
