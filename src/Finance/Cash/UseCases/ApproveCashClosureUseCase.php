<?php

namespace Src\Finance\Cash\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\Cash\Services\CashEventPublisher;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;
use Src\Shared\ValueObjects\Money;

/**
 * Aprueba un cierre con descuadre. Recién aquí la sesión pasa a `closed` y se
 * emite `cash.closed`.
 */
class ApproveCashClosureUseCase
{
    public function __construct(
        private readonly CashBalanceCalculator $balance,
        private readonly CashEventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $closureId, AgencyScope $scope): CashClosure
    {
        return DB::transaction(function () use ($closureId, $scope): CashClosure {
            $closure = CashClosure::query()->lockForUpdate()->find($closureId);

            if ($closure === null) {
                throw new BusinessRuleException(
                    message: 'The cash closure does not exist.',
                    errorCode: 'CASH_CLOSURE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $closure->isPending()) {
                throw new BusinessRuleException(
                    message: 'Only a pending closure can be approved.',
                    errorCode: 'CASH_CLOSURE_NOT_PENDING',
                );
            }

            $session = CashSession::query()->lockForUpdate()->findOrFail($closure->cash_session_id);

            if (! $scope->covers((int) $session->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The cash closure belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if ((int) $closure->requested_by === (int) auth()->id()) {
                throw new BusinessRuleException(
                    message: 'The approver must be different from the requester.',
                    errorCode: 'CASH_CLOSURE_SELF_APPROVAL',
                );
            }

            if ($session->status !== CashSession::STATUS_OPEN) {
                throw new BusinessRuleException(
                    message: 'The cash session is not open.',
                    errorCode: 'CASH_SESSION_NOT_OPEN',
                );
            }

            // La sesión siguió abierta mientras el cierre esperaba: si entró un
            // movimiento, la diferencia contada ya no es la real y hay que recontar.
            if (! $this->balance->expected($session)->equals(Money::fromDecimal((string) $closure->expected_amount))) {
                throw new BusinessRuleException(
                    message: 'The cash session changed after the count. Reject the closure and count again.',
                    errorCode: 'CASH_CLOSURE_OUTDATED',
                );
            }

            $closure->update([
                'status' => CashClosure::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $session->update([
                'status' => CashSession::STATUS_CLOSED,
                'expected_amount' => $closure->expected_amount,
                'closing_amount' => $closure->closing_amount,
                'difference' => $closure->difference,
                'closed_at' => now(),
            ]);

            CashMovement::create([
                'cash_session_id' => $session->id,
                'type' => CashMovement::TYPE_CLOSING,
                'amount' => $closure->closing_amount,
                'reference_type' => 'cash_closure',
                'reference_id' => $closure->id,
                'note' => 'Closure approved with difference '.$closure->difference,
                'created_by' => auth()->id(),
            ]);

            $this->audit->record(
                action: 'cash.closure.approved',
                entityType: 'cash_closure',
                entityId: $closure->id,
                before: [
                    'status' => CashClosure::STATUS_PENDING,
                    'difference' => $closure->difference,
                ],
                after: [
                    'status' => CashClosure::STATUS_APPROVED,
                    'approved_by' => auth()->id(),
                    'difference' => $closure->difference,
                ],
            );

            $session->refresh();
            $closure->setRelation('cashSession', $session);
            $this->events->closed($closure);

            return $closure->refresh()->load('cashSession.movements');
        });
    }
}
