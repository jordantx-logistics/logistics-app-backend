<?php

namespace Src\Finance\Cash\Services;

use Src\Finance\Cash\Models\CashSession;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Resuelve la agencia operativa del cajero autenticado.
 *
 * La agencia no se acepta del cliente: sale del pivote `agency_user`, con la
 * marcada como primaria como primera opción. Si no tiene ninguna, no puede
 * abrir caja.
 */
class CashierAgencyResolver
{
    public function resolve(): int
    {
        $user = auth()->user();

        if ($user === null) {
            throw new BusinessRuleException(
                message: 'An authenticated cashier is required.',
                errorCode: 'CASH_AGENCY_REQUIRED',
                statusCode: 401,
            );
        }

        $agency = $user->agencies()
            ->orderByDesc('agency_user.is_primary')
            ->orderBy('agencies.id')
            ->first();

        if ($agency === null) {
            throw new BusinessRuleException(
                message: 'The cashier has no agency assigned.',
                errorCode: 'CASH_AGENCY_REQUIRED',
            );
        }

        return (int) $agency->id;
    }

    public function currentOpenSession(): ?CashSession
    {
        return CashSession::query()
            ->open()
            ->where('cashier_id', (int) auth()->id())
            ->latest('id')
            ->first();
    }
}
