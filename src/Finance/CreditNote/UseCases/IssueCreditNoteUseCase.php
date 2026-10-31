<?php

namespace Src\Finance\CreditNote\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\Services\BillingStatusService;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;
use Src\Shared\ValueObjects\Money;

/**
 * Emite una nota de crédito sobre un comprobante aceptado.
 *
 * Puede ser total o parcial. Si cubre el total, el pago queda `refunded` y el
 * comprobante `voided`; si es parcial, el pago sigue `registered` y el
 * comprobante conserva su estado. Un pago en efectivo deja un egreso de caja.
 */
class IssueCreditNoteUseCase
{
    public function __construct(
        private readonly CashBalanceCalculator $balance,
        private readonly AuditLogger $audit,
        private readonly BillingStatusService $billingStatus,
    ) {}

    public function execute(array $data, AgencyScope $scope): CreditNote
    {
        return DB::transaction(function () use ($data, $scope): CreditNote {
            $payment = Payment::query()->lockForUpdate()->find((int) $data['payment_id']);

            if ($payment === null) {
                throw new BusinessRuleException(
                    message: 'The payment does not exist.',
                    errorCode: 'CREDIT_NOTE_PAYMENT_NOT_FOUND',
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
                    message: 'A voided payment cannot receive a credit note.',
                    errorCode: 'CREDIT_NOTE_PAYMENT_VOIDED',
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

            if ($invoice === null || ! $invoice->isAccepted()) {
                throw new BusinessRuleException(
                    message: 'A credit note requires an accepted invoice.',
                    errorCode: 'CREDIT_NOTE_INVOICE_NOT_ACCEPTED',
                );
            }

            $amount = Money::fromDecimal($data['amount'], (string) $payment->currency);

            if (! $amount->isPositive()) {
                throw new BusinessRuleException(
                    message: 'The credit note amount must be greater than zero.',
                    errorCode: 'CREDIT_NOTE_AMOUNT_INVALID',
                );
            }

            $alreadyCredited = CreditNote::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', [CreditNote::STATUS_ISSUED, CreditNote::STATUS_ACCEPTED])
                ->pluck('amount')
                ->reduce(
                    fn (int $cents, mixed $value): int => $cents + Money::fromDecimal((string) $value)->cents,
                    0,
                );

            $paymentAmount = Money::fromDecimal((string) $payment->amount);
            $creditable = $paymentAmount->subtract(Money::fromCents($alreadyCredited));

            if (! $creditable->isPositive() || $amount->greaterThan($creditable)) {
                throw new BusinessRuleException(
                    message: 'The credit note exceeds the refundable amount of the payment.',
                    errorCode: 'CREDIT_NOTE_AMOUNT_EXCEEDS_PAYMENT',
                );
            }

            $reason = (string) $data['reason'];

            if (! in_array($reason, CreditNote::REASONS, true)) {
                throw new BusinessRuleException(
                    message: 'The credit note reason is not in the SUNAT catalog.',
                    errorCode: 'CREDIT_NOTE_REASON_INVALID',
                );
            }

            $note = CreditNote::create([
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'agency_id' => $payment->agency_id,
                'cash_session_id' => $payment->cash_session_id,
                'amount' => $amount->toDecimal(),
                'currency' => $payment->currency,
                'reason' => $reason,
                'note' => $data['note'] ?? null,
                'status' => CreditNote::STATUS_ISSUED,
                'created_by' => auth()->id(),
                'issued_at' => now(),
            ]);

            if ($payment->movesCash() && $payment->cash_session_id !== null) {
                CashMovement::create([
                    'cash_session_id' => $payment->cash_session_id,
                    'type' => CashMovement::TYPE_EXPENSE,
                    'amount' => $amount->toDecimal(),
                    'reference_type' => 'credit_note',
                    'reference_id' => $note->id,
                    'note' => 'Credit note '.$note->ticket_number,
                    'created_by' => auth()->id(),
                ]);

                if ($session !== null && $session->isOpen()) {
                    $session->update([
                        'expected_amount' => $this->balance->expected($session->refresh())->toDecimal(),
                    ]);
                }
            }

            $totalCredited = Money::fromCents($alreadyCredited + $amount->cents);
            $isFull = $totalCredited->greaterThanOrEqual($paymentAmount);

            $payment->update([
                'status' => $isFull ? Payment::STATUS_REFUNDED : Payment::STATUS_REGISTERED,
            ]);

            if ($isFull) {
                $invoice->update(['status' => Invoice::STATUS_VOIDED]);
            }

            BillingOrder::query()->where('order_id', $payment->order_id)->update([
                'refunded_amount' => DB::raw('refunded_amount + '.$amount->toDecimal()),
                'updated_at' => now(),
            ]);

            $this->billingStatus->sync((int) $payment->order_id);

            OutboxEvent::create([
                'event_type' => 'credit-note.issued',
                'payload' => [
                    'credit_note_id' => $note->id,
                    'ticket_number' => $note->ticket_number,
                    'order_id' => $note->order_id,
                    'payment_id' => $note->payment_id,
                    'invoice_id' => $note->invoice_id,
                    'amount' => $note->amount,
                    'reason' => $note->reason,
                    'issued_at' => $note->issued_at?->toIso8601String(),
                ],
            ]);

            OutboxEvent::create([
                'event_type' => 'payment.refunded',
                'payload' => [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'amount' => $amount->toDecimal(),
                    'refunded_total' => $totalCredited->toDecimal(),
                    'payment_amount' => $payment->amount,
                    'payment_status' => $payment->status,
                    'refunded_at' => now()->toIso8601String(),
                ],
            ]);

            $this->audit->record(
                action: 'credit_note.issued',
                entityType: 'credit_note',
                entityId: $note->id,
                before: [
                    'payment_status' => Payment::STATUS_REGISTERED,
                    'refunded_total' => Money::fromCents($alreadyCredited)->toDecimal(),
                ],
                after: [
                    'payment_status' => $payment->status,
                    'invoice_status' => $invoice->status,
                    'amount' => $amount->toDecimal(),
                    'reason' => $reason,
                ],
            );

            return $note->load(['payment', 'invoice']);
        });
    }
}
