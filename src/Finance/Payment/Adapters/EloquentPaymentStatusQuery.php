<?php

namespace Src\Finance\Payment\Adapters;

use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\ValueObjects\Money;

/**
 * Implementación de `PaymentStatusQuery` para Core.
 *
 * No hace JOIN contra tablas de Core: lee la proyección local y los pagos del
 * propio contexto. El neto se calcula en céntimos para no arrastrar errores de
 * coma flotante.
 */
class EloquentPaymentStatusQuery implements PaymentStatusQuery
{
    public function isPaid(int $orderId): bool
    {
        $billing = BillingOrder::query()->where('order_id', $orderId)->first();

        if ($billing === null) {
            return false;
        }

        $due = Money::fromDecimal($billing->amount_due, $billing->currency);

        return $due->isPositive() && $this->paidAmountOf($orderId)->greaterThanOrEqual($due);
    }

    public function balanceOf(int $orderId): Money
    {
        $billing = BillingOrder::query()->where('order_id', $orderId)->first();

        if ($billing === null) {
            return Money::zero();
        }

        $due = Money::fromDecimal($billing->amount_due, $billing->currency);
        $balance = $due->subtract($this->paidAmountOf($orderId));

        return $balance->isNegative() ? Money::zero($due->currency) : $balance;
    }

    public function paidAmountOf(int $orderId): Money
    {
        $currency = BillingOrder::query()->where('order_id', $orderId)->value('currency') ?? 'PEN';

        $paid = Payment::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', Payment::STATUS_VOIDED)
            ->pluck('amount')
            ->reduce(
                fn (int $cents, mixed $amount): int => $cents + Money::fromDecimal((string) $amount)->cents,
                0,
            );

        $refunded = CreditNote::query()
            ->where('order_id', $orderId)
            ->whereIn('status', [CreditNote::STATUS_ISSUED, CreditNote::STATUS_ACCEPTED])
            ->pluck('amount')
            ->reduce(
                fn (int $cents, mixed $amount): int => $cents + Money::fromDecimal((string) $amount)->cents,
                0,
            );

        return Money::fromCents(max(0, $paid - $refunded), (string) $currency);
    }
}
