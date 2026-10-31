<?php

namespace Src\Finance\Payment\UseCases;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashBalanceCalculator;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Services\DocumentTypeResolver;
use Src\Finance\Invoice\Services\IgvCalculator;
use Src\Finance\Invoice\Services\InvoiceNumberGenerator;
use Src\Finance\Invoice\Services\InvoiceSeriesResolver;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\Services\BillingStatusService;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\OrderQuery;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\DTOs\BillableOrder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\ValueObjects\Money;

/**
 * Registra un cobro total o parcial.
 *
 * Todo ocurre en una transacción: pago, comprobante (fila y correlativo),
 * movimiento de caja y eventos. La emisión real del XML/PDF y el envío a SUNAT
 * quedan para el pipeline asíncrono (`payment.registered`).
 */
class RegisterPaymentUseCase
{
    public function __construct(
        private readonly OrderQuery $orders,
        private readonly PaymentStatusQuery $paymentStatus,
        private readonly AgencyDirectory $agencies,
        private readonly InvoiceSeriesResolver $seriesResolver,
        private readonly InvoiceNumberGenerator $numberGenerator,
        private readonly IgvCalculator $igv,
        private readonly DocumentTypeResolver $documentTypes,
        private readonly CashBalanceCalculator $cashBalance,
        private readonly BillingStatusService $billingStatus,
    ) {}

    /**
     * @param  array{order_id: int, amount: string|float|int, currency?: string, payment_method: string, document_type?: string|null, idempotency_key: string, reference?: string|null}  $data
     */
    public function execute(array $data, AgencyScope $scope): Payment
    {
        $existing = $this->findExisting($data, $scope);

        if ($existing !== null) {
            return $existing;
        }

        // Estas lecturas van fuera de la transacción a propósito. En REPEATABLE
        // READ la primera lectura sin bloqueo fija la foto de la transacción: si
        // ocurriera antes de conseguir el bloqueo de la proyección, el saldo se
        // calcularía sin ver los cobros de quien tenía el bloqueo.
        $sessionId = CashSession::query()
            ->open()
            ->where('cashier_id', (int) auth()->id())
            ->latest('id')
            ->value('id');

        $order = $this->orders->findBillable((int) $data['order_id']);

        if ($order?->isBillable()) {
            $this->project($order);
        }

        try {
            return $this->register($data, $scope, $sessionId, $order);
        } catch (UniqueConstraintViolationException $exception) {
            // Otra petición con la misma clave ganó la carrera por otra orden.
            return $this->findExisting($data, $scope) ?? throw $exception;
        }
    }

    private function findExisting(array $data, AgencyScope $scope): ?Payment
    {
        $existing = Payment::query()
            ->with(['currentInvoice'])
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing === null) {
            return null;
        }

        $sameAmount = Money::fromDecimal((string) $existing->amount)->equals(
            Money::fromDecimal($data['amount'], (string) $existing->currency),
        );

        if (! $scope->covers((int) $existing->agency_id)
            || (int) $existing->order_id !== (int) $data['order_id']
            || ! $sameAmount
            || $existing->payment_method !== $data['payment_method']) {
            throw new BusinessRuleException(
                message: 'The idempotency key was already used with a different payment.',
                errorCode: 'PAYMENT_IDEMPOTENCY_CONFLICT',
                statusCode: 409,
            );
        }

        return $existing;
    }

    private function register(array $data, AgencyScope $scope, ?int $sessionId, ?BillableOrder $order): Payment
    {
        return DB::transaction(function () use ($data, $scope, $sessionId, $order): Payment {
            // Por clave primaria: bloquear por el índice de cajero/estado invierte
            // el orden de bloqueo frente al cierre, que entra por la clave primaria.
            $session = $sessionId === null ? null : CashSession::query()->lockForUpdate()->find($sessionId);

            if ($session === null || ! $session->isOpen()) {
                throw new BusinessRuleException(
                    message: 'An open cash session is required to register a payment.',
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

            if ($order === null) {
                throw new BusinessRuleException(
                    message: 'The order does not exist.',
                    errorCode: 'ORDER_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $order->isBillable()) {
                throw new BusinessRuleException(
                    message: 'The order cannot be charged because it is cancelled.',
                    errorCode: 'ORDER_NOT_BILLABLE',
                );
            }

            $billing = BillingOrder::query()
                ->where('order_id', $order->orderId)
                ->lockForUpdate()
                ->firstOrFail();

            // Con la orden ya serializada: un reintento simultáneo con la misma
            // clave devuelve el pago original aunque este haya saldado la orden.
            $existing = $this->findExisting($data, $scope);

            if ($existing !== null) {
                return $existing;
            }

            $amount = Money::fromDecimal($data['amount'], $order->totalAmount->currency);

            if (! $amount->isPositive()) {
                throw new BusinessRuleException(
                    message: 'The payment amount must be greater than zero.',
                    errorCode: 'PAYMENT_AMOUNT_INVALID',
                );
            }

            $paid = $this->paymentStatus->paidAmountOf($order->orderId);
            $balance = $order->totalAmount->subtract($paid);

            if (! $balance->isPositive()) {
                throw new BusinessRuleException(
                    message: 'The order is already fully paid.',
                    errorCode: 'PAYMENT_ALREADY_SETTLED',
                );
            }

            if ($amount->greaterThan($balance)) {
                throw new BusinessRuleException(
                    message: 'The payment amount exceeds the outstanding balance.',
                    errorCode: 'PAYMENT_AMOUNT_EXCEEDS_BALANCE',
                );
            }

            $documentType = $this->documentTypes->resolve(
                $data['document_type'] ?? null,
                $order->fiscalDocument,
            );

            if ($documentType === Payment::DOCUMENT_FACTURA && ! ($order->fiscalDocument?->isCompany() ?? false)) {
                throw new BusinessRuleException(
                    message: 'A factura requires a customer RUC.',
                    errorCode: 'INVOICE_FISCAL_DATA_REQUIRED',
                );
            }

            $series = $this->seriesResolver->resolve((int) $session->agency_id, $documentType);

            if ($series === null) {
                throw new BusinessRuleException(
                    message: 'No active invoice series is configured for this agency and document type.',
                    errorCode: 'INVOICE_SERIES_NOT_CONFIGURED',
                );
            }

            $agencyCode = $this->agencies->agencyCode((int) $session->agency_id) ?? 'AG'.$session->agency_id;
            $reservation = $this->numberGenerator->reserve($series->id, $agencyCode);

            $payment = Payment::create([
                'ticket_number' => null,
                'ticket_code' => null,
                'idempotency_key' => $data['idempotency_key'],
                'order_id' => $order->orderId,
                'cash_session_id' => $session->id,
                'agency_id' => $session->agency_id,
                'amount' => $amount->toDecimal(),
                'currency' => $amount->currency,
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'document_type' => $documentType,
                'status' => Payment::STATUS_REGISTERED,
                'created_by' => auth()->id(),
                'paid_at' => now(),
            ]);

            $breakdown = $this->igv->breakdown($amount);

            Invoice::create([
                'ticket_number' => $reservation['ticket_number'],
                'order_id' => $order->orderId,
                'payment_id' => $payment->id,
                'agency_id' => $session->agency_id,
                'invoice_series_id' => $series->id,
                'document_type' => $documentType,
                'series' => $series->series,
                'number' => $reservation['number'],
                'customer_document_type' => $order->fiscalDocument?->type,
                'customer_document_number' => $order->fiscalDocument?->number,
                'customer_name' => $order->payerName,
                'taxable_amount' => $breakdown['taxable']->toDecimal(),
                'igv' => $breakdown['igv']->toDecimal(),
                'total' => $amount->toDecimal(),
                'currency' => $amount->currency,
                'status' => Invoice::STATUS_PENDING,
            ]);

            if ($payment->movesCash()) {
                CashMovement::create([
                    'cash_session_id' => $session->id,
                    'type' => CashMovement::TYPE_INCOME,
                    'amount' => $amount->toDecimal(),
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'note' => null,
                    'created_by' => auth()->id(),
                ]);

                $session->update([
                    'expected_amount' => $this->cashBalance->expected($session->refresh())->toDecimal(),
                ]);
            }

            $newPaid = Money::fromDecimal((string) $billing->paid_amount)->add($amount);
            $billing->update(['paid_amount' => $newPaid->toDecimal()]);
            $this->billingStatus->sync($order->orderId);

            OutboxEvent::create([
                'event_type' => 'payment.registered',
                'payload' => [
                    'payment_id' => $payment->id,
                    'ticket_number' => $payment->ticket_number,
                    'order_id' => $payment->order_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'payment_method' => $payment->payment_method,
                    'cash_session_id' => $payment->cash_session_id,
                    'agency_id' => $payment->agency_id,
                    'document_type' => $payment->document_type,
                    'registered_at' => $payment->paid_at?->toIso8601String(),
                ],
            ]);

            return $payment->load(['currentInvoice']);
        });
    }

    /**
     * Asegura la proyección si `order.created` aún no llegó. Va fuera de la
     * transacción del cobro: dos `INSERT` simultáneos en ella se quedarían con un
     * bloqueo compartido y se bloquearían entre sí al pedir el exclusivo.
     */
    private function project(BillableOrder $order): void
    {
        if (BillingOrder::query()->where('order_id', $order->orderId)->exists()) {
            return;
        }

        BillingOrder::query()->insertOrIgnore([
            'order_id' => $order->orderId,
            'ticket_number' => $order->ticketNumber,
            'ticket_code' => $order->ticketCode,
            'status' => $order->status,
            'origin_agency_id' => $order->originAgencyId,
            'destination_agency_id' => $order->destinationAgencyId,
            'sender_id' => $order->senderId,
            'receiver_id' => $order->receiverId,
            'payer_id' => $order->payerId,
            'payer_name' => $order->payerName,
            'customer_document_type' => $order->fiscalDocument?->type,
            'customer_document_number' => $order->fiscalDocument?->number,
            'amount_due' => $order->totalAmount->toDecimal(),
            'paid_amount' => '0.00',
            'refunded_amount' => '0.00',
            'payment_status' => BillingOrder::PAYMENT_UNPAID,
            'currency' => $order->totalAmount->currency,
            'payment_responsibility' => $order->paymentResponsibility,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
