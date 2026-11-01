<?php

namespace Src\Finance\Void\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Solicita la anulación de un pago. La aprobación es otro paso y otro permiso.
 */
class RequestVoidUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(array $data, AgencyScope $scope): PaymentVoid
    {
        return DB::transaction(function () use ($data, $scope): PaymentVoid {
            $payment = Payment::query()->lockForUpdate()->find((int) $data['payment_id']);

            if ($payment === null) {
                throw new BusinessRuleException(
                    message: 'The payment does not exist.',
                    errorCode: 'VOID_PAYMENT_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $payment->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The payment belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if ($payment->status === Payment::STATUS_VOIDED) {
                throw new BusinessRuleException(
                    message: 'The payment is already voided.',
                    errorCode: 'VOID_ALREADY_APPROVED',
                );
            }

            $existing = PaymentVoid::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', [PaymentVoid::STATUS_REQUESTED, PaymentVoid::STATUS_APPROVED])
                ->exists();

            if ($existing) {
                throw new BusinessRuleException(
                    message: 'The payment already has a void in progress or approved.',
                    errorCode: 'VOID_ALREADY_EXISTS',
                );
            }

            $invoice = Invoice::query()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if ($invoice !== null && $invoice->isAccepted()) {
                throw new BusinessRuleException(
                    message: 'The accepted invoice must be credited before the payment can be voided.',
                    errorCode: 'VOID_REQUIRES_CREDIT_NOTE',
                );
            }

            $reason = trim((string) ($data['reason'] ?? ''));

            if ($reason === '') {
                throw new BusinessRuleException(
                    message: 'A void reason is required.',
                    errorCode: 'VOID_REASON_REQUIRED',
                );
            }

            $void = PaymentVoid::create([
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice?->id,
                'reason' => $reason,
                'status' => PaymentVoid::STATUS_REQUESTED,
                'requested_by' => auth()->id(),
                'requested_at' => now(),
            ]);

            $this->audit->record(
                action: 'void.requested',
                entityType: 'void',
                entityId: $void->id,
                before: ['payment_status' => $payment->status],
                after: [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'invoice_id' => $invoice?->id,
                    'reason' => $reason,
                ],
            );

            return $void->load(['payment', 'invoice']);
        });
    }
}
