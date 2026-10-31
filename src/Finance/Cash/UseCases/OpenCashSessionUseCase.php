<?php

namespace Src\Finance\Cash\UseCases;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashierAgencyResolver;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\ValueObjects\Money;

/**
 * Abre el turno de caja del cajero en su agencia primaria.
 *
 * Un cajero no puede tener dos sesiones abiertas: lo garantiza el índice único
 * de `cash_sessions.open_cashier_id`, que resuelve dos aperturas simultáneas.
 */
class OpenCashSessionUseCase
{
    public function __construct(
        private readonly CashierAgencyResolver $agencyResolver,
    ) {}

    public function execute(array $data, AgencyScope $scope): CashSession
    {
        $agencyId = $this->agencyResolver->resolve();

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'Your user cannot operate the cash of this agency.',
                errorCode: 'CASH_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        try {
            return DB::transaction(function () use ($agencyId, $data): CashSession {
                $open = CashSession::query()
                    ->open()
                    ->where('cashier_id', (int) auth()->id())
                    ->exists();

                if ($open) {
                    throw $this->alreadyOpen();
                }

                $amount = Money::fromDecimal($data['opening_amount'] ?? 0);

                if ($amount->isNegative()) {
                    throw new BusinessRuleException(
                        message: 'The opening amount cannot be negative.',
                        errorCode: 'CASH_OPENING_AMOUNT_INVALID',
                    );
                }

                $session = CashSession::create([
                    'agency_id' => $agencyId,
                    'cashier_id' => auth()->id(),
                    'status' => CashSession::STATUS_OPEN,
                    'opening_amount' => $amount->toDecimal(),
                    'expected_amount' => $amount->toDecimal(),
                    'opened_at' => now(),
                ]);

                CashMovement::create([
                    'cash_session_id' => $session->id,
                    'type' => CashMovement::TYPE_OPENING,
                    'amount' => $amount->toDecimal(),
                    'reference_type' => null,
                    'reference_id' => null,
                    'note' => null,
                    'created_by' => auth()->id(),
                ]);

                return $session->load('movements');
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyOpen();
        }
    }

    private function alreadyOpen(): BusinessRuleException
    {
        return new BusinessRuleException(
            message: 'You already have an open cash session.',
            errorCode: 'CASH_SINGLE_OPEN_SESSION',
        );
    }
}
