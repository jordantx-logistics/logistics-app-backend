<?php

namespace Src\Finance\Invoice\Services;

use Src\Finance\Invoice\Models\InvoiceSeries;

/**
 * Reserva el siguiente correlativo de una serie con bloqueo de fila.
 *
 * La llamada debe ocurrir dentro de la transacción del pago: si el pago falla,
 * el `next_number` vuelve a su valor anterior.
 */
class InvoiceNumberGenerator
{
    /**
     * @return array{number: int, ticket_number: string}
     */
    public function reserve(int $seriesId, string $agencyCode): array
    {
        $series = InvoiceSeries::query()->lockForUpdate()->findOrFail($seriesId);

        $number = (int) $series->next_number;

        $series->update(['next_number' => $number + 1]);

        return [
            'number' => $number,
            'ticket_number' => sprintf('%s-%s-%06d', $agencyCode, $series->series, $number),
        ];
    }
}
