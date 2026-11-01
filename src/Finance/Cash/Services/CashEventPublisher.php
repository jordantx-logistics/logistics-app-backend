<?php

namespace Src\Finance\Cash\Services;

use Src\Finance\Cash\Models\CashClosure;
use Src\Shared\Models\OutboxEvent;

/**
 * Publica `cash.closed` después de cerrar una sesión.
 *
 * Vive en un servicio porque el cierre cuadrado y el cierre aprobado con
 * descuadre tienen que emitir exactamente el mismo contrato.
 */
class CashEventPublisher
{
    public function closed(CashClosure $closure): void
    {
        $session = $closure->cashSession;

        OutboxEvent::create([
            'event_type' => 'cash.closed',
            'payload' => [
                'cash_session_id' => $session->id,
                'cash_closure_id' => $closure->id,
                'agency_id' => $session->agency_id,
                'cashier_id' => $session->cashier_id,
                'opening_amount' => $session->opening_amount,
                'expected_amount' => $closure->expected_amount,
                'closing_amount' => $closure->closing_amount,
                'difference' => $closure->difference,
                'closed_at' => $session->closed_at?->toIso8601String(),
            ],
        ]);
    }
}
