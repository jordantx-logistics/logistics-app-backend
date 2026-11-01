<?php

namespace Src\Finance\Payment\Services;

use Src\Finance\Payment\Models\BillingOrder;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\ValueObjects\Money;

/**
 * Recalcula el estado de cobro de una orden a partir del neto real
 * (pagos registrados menos notas de crédito y anulaciones).
 */
class BillingStatusService
{
    public function __construct(
        private readonly PaymentStatusQuery $payments,
    ) {}

    public function sync(int $orderId): void
    {
        $billing = BillingOrder::query()->where('order_id', $orderId)->first();

        if ($billing === null) {
            return;
        }

        $net = $this->payments->paidAmountOf($orderId);
        $due = Money::fromDecimal((string) $billing->amount_due, (string) $billing->currency);
        $hasReversals = Money::fromDecimal((string) $billing->refunded_amount)->isPositive();

        $status = match (true) {
            ! $net->isPositive() => $hasReversals
                ? BillingOrder::PAYMENT_REFUNDED
                : BillingOrder::PAYMENT_UNPAID,
            $net->greaterThanOrEqual($due) => BillingOrder::PAYMENT_PAID,
            default => BillingOrder::PAYMENT_PARTIAL,
        };

        $billing->update(['payment_status' => $status]);
    }
}
