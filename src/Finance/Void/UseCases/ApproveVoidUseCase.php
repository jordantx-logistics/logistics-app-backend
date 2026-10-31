<?php

namespace Src\Finance\Void\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\Services\BillingStatusService;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;
use Src\Shared\ValueObjects\Money;

/**
 * Aprueba una anulación.
 *
 * El aprobador debe ser distinto del solicitante. Si el comprobante sigue
 * aceptado no se puede avanzar: primero hay que emitir la nota de crédito. Un
 * pago en efectivo sin reembolso previo deja el egreso de caja correspondiente.
 */
class ApproveVoidUseCase
{
    public function __construct(
        private readonly CashBalanceCalculator $balance,
        private readonly AuditLogger $audit,
        private readonly BillingStatusService $billingStatus,
    ) {}

    public function execute(int $voidId, AgencyScope $scope): PaymentVoid
    {
        return DB::transaction(function () use ($voidId, $scope): PaymentVoid {
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
                    message: 'Only a requested void can be approved.',
                    errorCode: 'VOID_NOT_REQUESTED',
                );
            }

            if ((int) $void->requested_by === (int) auth()->id()) {
                throw new BusinessRuleException(
                    message: 'The approver must be different from the requester.',
                    errorCode: 'VOID_SELF_APPROVAL',
                );
            }

            $payment = Payment::query()->lockForUpdate()->find($void->payment_id);

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

            // Antes de cualquier lectura sin bloqueo, para que el esperado vea los
            // cobros que tenían la sesión bloqueada (REPEATABLE READ).
            $session = $payment->movesCash() && $payment->cash_session_id !== null
                ? CashSession::query()->lockForUpdate()->find($payment->cash_session_id)
                : null;

            $invoice = Invoice::query()
                ->where('payment_id', $payment->id)
                ->latest('id')
                ->first();

            if ($invoice !== null && $invoice->isAccepted()) {
                throw new BusinessRuleException(
                    message: 'The accepted invoice must be credited before the void can be approved.',
                    errorCode: 'VOID_REQUIRES_CREDIT_NOTE',
                );
            }

            $beforePaymentStatus = $payment->status;

            if ($beforePaymentStatus === Payment::STATUS_REGISTERED && $payment->movesCash() && $payment->cash_session_id !== null) {
                CashMovement::create([
                    'cash_session_id' => $payment->cash_session_id,
                    'type' => CashMovement::TYPE_EXPENSE,
                    'amount' => $payment->amount,
                    'reference_type' => 'void',
                    'reference_id' => $void->id,
                    'note' => 'Void '.$void->reason,
                    'created_by' => auth()->id(),
                ]);

                if ($session !== null && $session->isOpen()) {
                    $session->update([
                        'expected_amount' => $this->balance->expected($session->refresh())->toDecimal(),
                    ]);
                }
            }

            $payment->update(['status' => Payment::STATUS_VOIDED]);

            if ($invoice !== null && ! $invoice->isAccepted()) {
                $invoice->update(['status' => Invoice::STATUS_VOIDED]);
            }

            $billing = BillingOrder::query()->where('order_id', $payment->order_id)->lockForUpdate()->first();

            if ($billing !== null) {
                $refunded = Money::fromDecimal((string) $billing->refunded_amount);

                if ($beforePaymentStatus !== Payment::STATUS_REFUNDED) {
                    $refunded = $refunded->add(Money::fromDecimal((string) $payment->amount));
                }

                $billing->update([
                    'refunded_amount' => $refunded->toDecimal(),
                    'updated_at' => now(),
                ]);

                $this->billingStatus->sync((int) $payment->order_id);
            }

            $void->update([
                'status' => PaymentVoid::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type' => 'payment.voided',
                'payload' => [
                    'payment_id' => $payment->id,
                    'void_id' => $void->id,
                    'order_id' => $payment->order_id,
                    'amount' => $payment->amount,
                    'payment_status' => $payment->status,
                    'refunded_before' => $beforePaymentStatus === Payment::STATUS_REFUNDED,
                    'approved_by' => auth()->id(),
                    'voided_at' => now()->toIso8601String(),
                ],
            ]);

            $this->audit->record(
                action: 'void.approved',
                entityType: 'void',
                entityId: $void->id,
                before: [
                    'payment_status' => $beforePaymentStatus,
                    'void_status' => PaymentVoid::STATUS_REQUESTED,
                ],
                after: [
                    'payment_status' => $payment->status,
                    'void_status' => PaymentVoid::STATUS_APPROVED,
                    'approved_by' => auth()->id(),
                    'reason' => $void->reason,
                ],
            );

            return $void->refresh()->load(['payment', 'invoice']);
        });
    }
}
