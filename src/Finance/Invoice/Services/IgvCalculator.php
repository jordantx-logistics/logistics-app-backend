<?php

namespace Src\Finance\Invoice\Services;

use Src\Shared\ValueObjects\Money;

/**
 * Reparte un total con IGV incluido en valor de venta e IGV.
 *
 * Perú: `total = taxable + igv`, con IGV 18 %. El cálculo se hace en céntimos
 * enteros y redondeo half-up, que es la precisión que exige SUNAT para el
 * comprobante (no se usa `float` para el reparto).
 */
class IgvCalculator
{
    public const RATE = 0.18;

    /**
     * @return array{taxable: Money, igv: Money, total: Money}
     */
    public function breakdown(Money $total): array
    {
        $totalCents = $total->cents;
        $taxableCents = (int) round($totalCents / 1.18, 0, PHP_ROUND_HALF_UP);
        $igvCents = $totalCents - $taxableCents;

        return [
            'taxable' => Money::fromCents($taxableCents, $total->currency),
            'igv' => Money::fromCents($igvCents, $total->currency),
            'total' => $total,
        ];
    }
}
