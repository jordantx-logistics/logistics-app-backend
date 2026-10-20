<?php

namespace Src\Logistics\Order\Services;

use Illuminate\Support\Facades\DB;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Models\OrderStatusHistory;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Máquina de estados de la orden.
 *
 * Centraliza la transición para que `confirm`, `cancel` y los eventos de
 * Operaciones (Fase 4) no repitan ni el mapa de transiciones ni la auditoría:
 * cada cambio actualiza `orders.version` (bloqueo optimista) y deja una fila en
 * `order_status_history`.
 *
 * Una transición no permitida responde `INVALID_STATUS_TRANSITION` (422).
 */
class OrderStatusMachine
{
    public function transition(Order $order, string $to, ?string $reason = null, ?int $changedBy = null): Order
    {
        if (! $order->canTransitionTo($to)) {
            throw new BusinessRuleException(
                message: "The order cannot transition from [{$order->status}] to [{$to}].",
                errorCode: 'INVALID_STATUS_TRANSITION',
            );
        }

        return DB::transaction(function () use ($order, $to, $reason, $changedBy): Order {
            $from = $order->status;
            $now = now();

            $attributes = [
                'status' => $to,
                'version' => $order->version + 1,
            ];

            if ($to === Order::STATUS_CONFIRMED) {
                $attributes['confirmed_at'] = $now;
            }

            if ($to === Order::STATUS_CANCELLED) {
                $attributes['cancelled_at'] = $now;
                $attributes['cancelled_reason'] = $reason;
            }

            if ($to === Order::STATUS_DELIVERED) {
                $attributes['delivered_at'] = $now;
            }

            $order->update($attributes);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by' => $changedBy ?? auth()->id(),
            ]);

            return $order->refresh();
        });
    }
}
