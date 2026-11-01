<?php

namespace Src\Finance\Cash\Services;

use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Shared\ValueObjects\Money;

/**
 * Calcula el efectivo esperado de una sesión.
 *
 * El movimiento `opening` es un registro de auditoría del monto inicial y no se
 * suma otra vez: la fórmula parte de `opening_amount`, suma ingresos y resta
 * egresos/retiros. `closing` tampoco altera el esperado.
 */
class CashBalanceCalculator
{
    public function expected(CashSession $session): Money
    {
        $expected = Money::fromDecimal((string) $session->opening_amount);

        $movements = $session->movements()
            ->whereIn('type', [CashMovement::TYPE_INCOME, CashMovement::TYPE_EXPENSE, CashMovement::TYPE_WITHDRAWAL])
            ->get();

        foreach ($movements as $movement) {
            $amount = Money::fromDecimal((string) $movement->amount);

            $expected = $movement->isOutgoing()
                ? $expected->subtract($amount)
                : $expected->add($amount);
        }

        return $expected;
    }
}
