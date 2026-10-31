<?php

namespace Src\Logistics\Order\Services;

use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Models\OrderPaymentProjection;
use Src\Shared\ValueObjects\Money;

/**
 * Mantiene `order_payment_projections` a partir de los eventos de Finanzas.
 *
 * Core nunca consulta `payments`: aplica incrementos idempotentes por evento y
 * deriva el estado del neto (cobrado menos reembolsado/anulado).
 */
class OrderPaymentProjector
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function registered(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return;
        }

        $projection = $this->projection($orderId);
        $projection->paid_amount = Money::fromDecimal((string) $projection->paid_amount)
            ->add(Money::fromDecimal((string) ($payload['amount'] ?? 0)))
            ->toDecimal();
        $projection->last_event_at = now();

        $this->refresh($projection, $order);
        $projection->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function refunded(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return;
        }

        $projection = $this->projection($orderId);
        $projection->refunded_amount = Money::fromDecimal((string) $projection->refunded_amount)
            ->add(Money::fromDecimal((string) ($payload['amount'] ?? 0)))
            ->toDecimal();
        $projection->last_event_at = now();

        $this->refresh($projection, $order);
        $projection->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function voided(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return;
        }

        $projection = $this->projection($orderId);

        // Si el pago ya se había reembolsado con nota de crédito, el importe ya
        // está en `refunded_amount`: sumarlo otra vez dejaría un neto negativo.
        if (! ($payload['refunded_before'] ?? false)) {
            $projection->refunded_amount = Money::fromDecimal((string) $projection->refunded_amount)
                ->add(Money::fromDecimal((string) ($payload['amount'] ?? 0)))
                ->toDecimal();
        }

        $projection->last_event_at = now();

        $this->refresh($projection, $order);
        $projection->save();
    }

    private function projection(int $orderId): OrderPaymentProjection
    {
        return OrderPaymentProjection::query()->firstOrCreate(
            ['order_id' => $orderId],
            [
                'paid_amount' => '0.00',
                'refunded_amount' => '0.00',
                'status' => OrderPaymentProjection::STATUS_UNPAID,
            ],
        );
    }

    private function refresh(OrderPaymentProjection $projection, Order $order): void
    {
        $paid = Money::fromDecimal((string) $projection->paid_amount);
        $refunded = Money::fromDecimal((string) $projection->refunded_amount);
        $net = $paid->subtract($refunded);
        $total = Money::fromDecimal((string) $order->total_amount);

        $projection->status = match (true) {
            $paid->isZero() => OrderPaymentProjection::STATUS_UNPAID,
            ! $net->isPositive() => OrderPaymentProjection::STATUS_REFUNDED,
            $net->greaterThanOrEqual($total) => OrderPaymentProjection::STATUS_PAID,
            default => OrderPaymentProjection::STATUS_PARTIAL,
        };
    }
}
