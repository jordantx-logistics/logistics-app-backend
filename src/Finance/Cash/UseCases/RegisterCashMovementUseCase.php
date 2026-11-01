<?php

namespace Src\Finance\Cash\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\Cash\Services\CashierAgencyResolver;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\ValueObjects\Money;

/**
 * Registra egresos y retiros del turno.
 *
 * Ingresos y apertura/cierre los generan los casos de uso de pago y cierre; por
 * esta puerta solo entran movimientos que sacan efectivo de la caja y siempre
 * con motivo.
 */
class RegisterCashMovementUseCase
{
    public function __construct(
        private readonly CashierAgencyResolver $agencyResolver,
        private readonly CashBalanceCalculator $balance,
    ) {}

    public function execute(array $data, AgencyScope $scope): CashMovement
    {
        // Fuera de la transacción: leerla dentro fijaría la foto de REPEATABLE READ
        // antes del bloqueo y el esperado no vería los cobros de quien lo tenía.
        $sessionId = $this->agencyResolver->currentOpenSession()?->id;

        return DB::transaction(function () use ($data, $scope, $sessionId): CashMovement {
            $session = $sessionId === null ? null : CashSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null || ! $session->isOpen()) {
                throw new BusinessRuleException(
                    message: 'An open cash session is required.',
                    errorCode: 'CASH_SESSION_OPEN_REQUIRED',
                );
            }

            if (! $scope->covers((int) $session->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The cash session belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if (! in_array($data['type'], [CashMovement::TYPE_EXPENSE, CashMovement::TYPE_WITHDRAWAL], true)) {
                throw new BusinessRuleException(
                    message: 'Only expense and withdrawal movements can be registered manually.',
                    errorCode: 'CASH_MOVEMENT_TYPE_INVALID',
                );
            }

            $amount = Money::fromDecimal($data['amount']);

            if (! $amount->isPositive()) {
                throw new BusinessRuleException(
                    message: 'The movement amount must be greater than zero.',
                    errorCode: 'CASH_MOVEMENT_AMOUNT_INVALID',
                );
            }

            if (trim((string) ($data['note'] ?? '')) === '') {
                throw new BusinessRuleException(
                    message: 'Expenses and withdrawals require a note.',
                    errorCode: 'CASH_MOVEMENT_NOTE_REQUIRED',
                );
            }

            $movement = CashMovement::create([
                'cash_session_id' => $session->id,
                'type' => $data['type'],
                'amount' => $amount->toDecimal(),
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'note' => $data['note'],
                'created_by' => auth()->id(),
            ]);

            $session->update([
                'expected_amount' => $this->balance->expected($session->refresh())->toDecimal(),
            ]);

            return $movement;
        });
    }
}
