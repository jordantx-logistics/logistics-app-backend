<?php

namespace Src\Finance\Cash\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashSession;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Rechaza un cierre con descuadre. La sesión sigue abierta y se limpian los
 * importes del intento para que el cajero pueda volver a contar.
 */
class RejectCashClosureUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $closureId, string $reason, AgencyScope $scope): CashClosure
    {
        return DB::transaction(function () use ($closureId, $reason, $scope): CashClosure {
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
                    message: 'Only a pending closure can be rejected.',
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

            if (trim($reason) === '') {
                throw new BusinessRuleException(
                    message: 'A rejection reason is required.',
                    errorCode: 'CASH_CLOSURE_REJECTION_REASON_REQUIRED',
                );
            }

            $closure->update([
                'status' => CashClosure::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $session->update([
                'expected_amount' => null,
                'closing_amount' => null,
                'difference' => null,
            ]);

            $this->audit->record(
                action: 'cash.closure.rejected',
                entityType: 'cash_closure',
                entityId: $closure->id,
                before: [
                    'status' => CashClosure::STATUS_PENDING,
                    'difference' => $closure->difference,
                ],
                after: [
                    'status' => CashClosure::STATUS_REJECTED,
                    'reason' => $reason,
                ],
            );

            return $closure->refresh()->load('cashSession');
        });
    }
}
