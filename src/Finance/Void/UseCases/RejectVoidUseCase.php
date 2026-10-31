<?php

namespace Src\Finance\Void\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Rechaza una solicitud de anulación. El pago conserva su estado.
 */
class RejectVoidUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $voidId, string $reason, AgencyScope $scope): PaymentVoid
    {
        return DB::transaction(function () use ($voidId, $reason, $scope): PaymentVoid {
            $void = PaymentVoid::query()->lockForUpdate()->find($voidId);

            if ($void === null) {
                throw new BusinessRuleException(
                    message: 'The void request does not exist.',
                    errorCode: 'VOID_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $void->isRequested()) {
                throw new BusinessRuleException(
                    message: 'Only a requested void can be rejected.',
                    errorCode: 'VOID_NOT_REQUESTED',
                );
            }

            $payment = Payment::query()->find($void->payment_id);

            if ($payment !== null && ! $scope->covers((int) $payment->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The payment belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if (trim($reason) === '') {
                throw new BusinessRuleException(
                    message: 'A rejection reason is required.',
                    errorCode: 'VOID_REJECTION_REASON_REQUIRED',
                );
            }

            $void->update([
                'status' => PaymentVoid::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->record(
                action: 'void.rejected',
                entityType: 'void',
                entityId: $void->id,
                before: ['status' => PaymentVoid::STATUS_REQUESTED],
                after: ['status' => PaymentVoid::STATUS_REJECTED, 'reason' => $reason],
            );

            return $void->refresh()->load(['payment', 'invoice']);
        });
    }
}
