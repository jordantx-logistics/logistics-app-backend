<?php

require_once __DIR__.'/FinanceHelpers.php';

use Illuminate\Support\Facades\Storage;
use Src\Customer\Models\Customer;
use Src\Finance\Cash\Jobs\GenerateCashCloseReportJob;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\UseCases\ApproveCashClosureUseCase;
use Src\Finance\Cash\UseCases\CloseCashSessionUseCase;
use Src\Finance\Cash\UseCases\RejectCashClosureUseCase;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\CreditNote\UseCases\IssueCreditNoteUseCase;
use Src\Finance\Invoice\Jobs\GenerateCreditNoteArtifactsJob;
use Src\Finance\Invoice\Jobs\GenerateInvoiceJob;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Finance\Void\UseCases\ApproveVoidUseCase;
use Src\Finance\Void\UseCases\RejectVoidUseCase;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Finanzas (Fase 5): cobros, caja, notas de crédito y anulaciones
|--------------------------------------------------------------------------
|
| Los casos de uso se ejercitan con su ámbito real. Los jobs del outbox se
| llaman a mano para no depender de un worker.
|
*/

it('registra un pago en efectivo, su comprobante y el ingreso de caja', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $user = financeUser($agency);
    $this->actingAs($user);
    financeSeries($agency);
    $order = financeOrder('25.90');

    $session = financeSession($agency);
    $payment = financePayment($agency, $order);

    $invoice = Invoice::query()->where('payment_id', $payment->id)->sole();

    expect($payment->status)->toBe(Payment::STATUS_REGISTERED)
        ->and($payment->agency_id)->toBe($agency->id)
        ->and($payment->cash_session_id)->toBe($session->id)
        ->and($invoice->status)->toBe(Invoice::STATUS_PENDING)
        ->and($invoice->ticket_number)->toBe('TES01-B001-000001')
        ->and($invoice->number)->toBe(1)
        ->and($invoice->taxable_amount)->toBe('21.95')
        ->and($invoice->igv)->toBe('3.95')
        ->and($invoice->total)->toBe('25.90')
        ->and(CashMovement::query()->where('cash_session_id', $session->id)->where('type', CashMovement::TYPE_INCOME)->count())->toBe(1)
        ->and($session->refresh()->expected_amount)->toBe('75.90')
        ->and(BillingOrder::query()->where('order_id', $order->id)->sole()->payment_status)->toBe(BillingOrder::PAYMENT_PAID)
        ->and(OutboxEvent::query()->where('event_type', 'payment.registered')->count())->toBe(1)
        ->and(app(PaymentStatusQuery::class)->balanceOf($order->id)->toDecimal())->toBe('0.00');

    expect($invoice->fresh()->xml_path)->toBeNull();

    $event = OutboxEvent::query()->where('event_type', 'payment.registered')->sole();
    app()->call([new GenerateInvoiceJob($event->id, $event->payload), 'handle']);

    $invoice->refresh();

    expect($invoice->status)->toBe(Invoice::STATUS_ACCEPTED)
        ->and($invoice->xml_path)->not->toBeNull()
        ->and($invoice->pdf_path)->not->toBeNull()
        ->and($invoice->cdr_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($invoice->xml_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($invoice->pdf_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($invoice->cdr_path))->toBeTrue()
        ->and(OutboxEvent::query()->where('event_type', 'invoice.accepted')->count())->toBe(1);
});

it('acepta pagos parciales, suma solo el efectivo y bloquea el exceso', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency, '10.00');

    $first = financePayment($agency, $order, '40.00', Payment::METHOD_CASH);
    $second = financePayment($agency, $order, '60.00', Payment::METHOD_POS);

    expect($first->status)->toBe(Payment::STATUS_REGISTERED)
        ->and($second->status)->toBe(Payment::STATUS_REGISTERED)
        ->and(CashMovement::query()->where('cash_session_id', $session->id)->where('type', CashMovement::TYPE_INCOME)->count())->toBe(1)
        ->and($session->refresh()->expected_amount)->toBe('50.00')
        ->and(BillingOrder::query()->where('order_id', $order->id)->sole()->payment_status)->toBe(BillingOrder::PAYMENT_PAID)
        ->and(app(PaymentStatusQuery::class)->isPaid($order->id))->toBeTrue()
        ->and(app(PaymentStatusQuery::class)->paidAmountOf($order->id)->toDecimal())->toBe('100.00');

    $alreadyPaid = financeError(fn () => financePayment($agency, $order, '0.01', Payment::METHOD_CASH));
    expect($alreadyPaid->errorCode)->toBe('PAYMENT_ALREADY_SETTLED');
});

it('rechaza un importe mayor al saldo y exige una sesión abierta', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');

    $noSession = financeError(fn () => financePayment($agency, $order, '50.00'));
    expect($noSession->errorCode)->toBe('CASH_SESSION_OPEN_REQUIRED');

    financeSession($agency);
    $tooMuch = financeError(fn () => financePayment($agency, $order, '100.01'));
    expect($tooMuch->errorCode)->toBe('PAYMENT_AMOUNT_EXCEEDS_BALANCE');
});

it('rechaza el pago sin serie, de una orden cancelada y con importe inválido', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    $order = financeOrder('100.00');
    financeSession($agency);

    $noSeries = financeError(fn () => financePayment($agency, $order, '10.00'));
    expect($noSeries->errorCode)->toBe('INVOICE_SERIES_NOT_CONFIGURED');

    financeSeries($agency);
    $cancelled = financeOrder('100.00', ['status' => Order::STATUS_CANCELLED]);
    $notBillable = financeError(fn () => financePayment($agency, $cancelled, '10.00'));
    expect($notBillable->errorCode)->toBe('ORDER_NOT_BILLABLE');

    $invalid = financeError(fn () => financePayment($agency, $order, '0'));
    expect($invalid->errorCode)->toBe('PAYMENT_AMOUNT_INVALID');
});

it('es idempotente por clave y rechaza una clave reutilizada con otro importe', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);

    $key = 'same-key';
    $first = financePayment($agency, $order, '10.00', Payment::METHOD_CASH, ['idempotency_key' => $key]);
    $second = financePayment($agency, $order, '10.00', Payment::METHOD_CASH, ['idempotency_key' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1);

    $conflict = financeError(fn () => financePayment($agency, $order, '11.00', Payment::METHOD_CASH, ['idempotency_key' => $key]));
    expect($conflict->errorCode)->toBe('PAYMENT_IDEMPOTENCY_CONFLICT');
});

it('emite factura solo con RUC y boleta con documento de persona', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSeries($agency, 'factura', 'F001');
    financeSession($agency);

    $company = Customer::factory()->forCompany()->create();
    $person = Customer::factory()->create();

    $invoiceOrder = financeOrder('100.00', [
        'sender_id' => $company->id,
        'receiver_id' => $person->id,
    ]);

    $payment = financePayment($agency, $invoiceOrder, '50.00');
    expect($payment->document_type)->toBe('factura')
        ->and($payment->currentInvoice)->not->toBeNull()
        ->and($payment->currentInvoice->series)->toBe('F001')
        ->and($payment->currentInvoice->customer_document_type)->toBe('RUC');

    $personOrder = financeOrder('100.00', [
        'sender_id' => $person->id,
        'receiver_id' => $person->id,
    ]);

    $error = financeError(fn () => financePayment($agency, $personOrder, '10.00', Payment::METHOD_CASH, ['document_type' => 'factura']));
    expect($error->errorCode)->toBe('INVOICE_FISCAL_DATA_REQUIRED');
});

it('cierra una caja cuadrada y emite su reporte', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $user = financeUser($agency);
    $this->actingAs($user);
    financeSeries($agency);
    $order = financeOrder('25.00');
    $session = financeSession($agency, '50.00');
    financePayment($agency, $order, '25.00');

    $result = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '75.00'], financeScope($agency));

    expect($result['requires_approval'])->toBeFalse()
        ->and($result['session']->status)->toBe(CashSession::STATUS_CLOSED)
        ->and($result['closure']->status)->toBe(CashClosure::STATUS_APPROVED)
        ->and($result['closure']->difference)->toBe('0.00')
        ->and(OutboxEvent::query()->where('event_type', 'cash.closed')->count())->toBe(1);

    $event = OutboxEvent::query()->where('event_type', 'cash.closed')->sole();
    app()->call([new GenerateCashCloseReportJob($event->id, $event->payload), 'handle']);

    expect($result['closure']->refresh()->report_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($result['closure']->report_path))->toBeTrue();

    $again = financeError(fn () => app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '75.00'], financeScope($agency)));
    expect($again->errorCode)->toBe('CASH_SESSION_ALREADY_CLOSED');
});

it('mantiene abierta la caja con descuadre hasta la aprobación de otro usuario', function () {
    $agency = financeAgency();
    $cashier = financeUser($agency);
    $this->actingAs($cashier);
    financeSeries($agency);
    $order = financeOrder('25.00');
    $session = financeSession($agency, '50.00');
    financePayment($agency, $order, '25.00');

    $pending = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '70.00'], financeScope($agency));

    expect($pending['requires_approval'])->toBeTrue()
        ->and($pending['session']->status)->toBe(CashSession::STATUS_OPEN)
        ->and($pending['closure']->status)->toBe(CashClosure::STATUS_PENDING)
        ->and($pending['closure']->difference)->toBe('-5.00');

    $again = financeError(fn () => app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '70.00'], financeScope($agency)));
    expect($again->errorCode)->toBe('CASH_CLOSURE_PENDING');

    $self = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency)));
    expect($self->errorCode)->toBe('CASH_CLOSURE_SELF_APPROVAL');

    $this->actingAs(financeUser($agency));
    $approved = app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency));

    expect($approved->status)->toBe(CashClosure::STATUS_APPROVED)
        ->and($session->refresh()->status)->toBe(CashSession::STATUS_CLOSED)
        ->and(OutboxEvent::query()->where('event_type', 'cash.closed')->count())->toBe(1);

    $repeat = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($approved->id, financeScope($agency)));
    expect($repeat->errorCode)->toBe('CASH_CLOSURE_NOT_PENDING');
});

it('rechaza un descuadre y permite volver a cerrar la caja', function () {
    $agency = financeAgency();
    $cashier = financeUser($agency);
    $this->actingAs($cashier);
    financeSeries($agency);
    $order = financeOrder('25.00');
    $session = financeSession($agency, '50.00');
    financePayment($agency, $order, '25.00');

    $pending = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '70.00'], financeScope($agency));

    $this->actingAs(financeUser($agency));
    $rejected = app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, 'Count was incomplete', financeScope($agency));

    expect($rejected->status)->toBe(CashClosure::STATUS_REJECTED)
        ->and($session->refresh()->status)->toBe(CashSession::STATUS_OPEN)
        ->and($session->expected_amount)->toBeNull();

    $this->actingAs($cashier);
    $balanced = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '75.00'], financeScope($agency));

    expect($balanced['requires_approval'])->toBeFalse()
        ->and($session->refresh()->status)->toBe(CashSession::STATUS_CLOSED);
});

it('emite notas de crédito parciales y totales, con egreso solo del efectivo', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency, '0.00');
    $payment = financePayment($agency, $order, '100.00');
    $invoice = $payment->currentInvoice;
    $invoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $partial = app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '40.00',
        'reason' => '01',
        'note' => 'Partial refund',
    ], financeScope($agency));

    expect($partial->status)->toBe(CreditNote::STATUS_ISSUED)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_REGISTERED)
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_ACCEPTED)
        ->and($session->refresh()->expected_amount)->toBe('60.00')
        ->and(BillingOrder::query()->where('order_id', $order->id)->sole()->payment_status)->toBe(BillingOrder::PAYMENT_PARTIAL);

    $full = app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '60.00',
        'reason' => '01',
        'note' => 'Full refund',
    ], financeScope($agency));

    expect($full->status)->toBe(CreditNote::STATUS_ISSUED)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_REFUNDED)
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_VOIDED)
        ->and($session->refresh()->expected_amount)->toBe('0.00')
        ->and(BillingOrder::query()->where('order_id', $order->id)->sole()->payment_status)->toBe(BillingOrder::PAYMENT_REFUNDED)
        ->and(OutboxEvent::query()->where('event_type', 'credit-note.issued')->count())->toBe(2)
        ->and(OutboxEvent::query()->where('event_type', 'payment.refunded')->count())->toBe(2);

    $event = OutboxEvent::query()->where('event_type', 'credit-note.issued')->latest('id')->first();
    app()->call([new GenerateCreditNoteArtifactsJob($event->id, $event->payload), 'handle']);

    $full->refresh();
    expect($full->status)->toBe(CreditNote::STATUS_ACCEPTED)
        ->and($full->xml_path)->not->toBeNull()
        ->and($full->pdf_path)->not->toBeNull()
        ->and($full->cdr_path)->not->toBeNull();
});

it('rechaza notas de crédito sin comprobante aceptado o por encima del pago', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');

    $notAccepted = financeError(fn () => app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '10.00',
        'reason' => '01',
    ], financeScope($agency)));
    expect($notAccepted->errorCode)->toBe('CREDIT_NOTE_INVOICE_NOT_ACCEPTED');

    $payment->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $tooMuch = financeError(fn () => app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '50.01',
        'reason' => '01',
    ], financeScope($agency)));
    expect($tooMuch->errorCode)->toBe('CREDIT_NOTE_AMOUNT_EXCEEDS_PAYMENT');

    $badReason = financeError(fn () => app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '10.00',
        'reason' => '99',
    ], financeScope($agency)));
    expect($badReason->errorCode)->toBe('CREDIT_NOTE_REASON_INVALID');
});

it('anula un pago no aceptado con egreso de caja y sin doble reembolso', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency, '0.00');
    $payment = financePayment($agency, $order, '100.00');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    expect($void->status)->toBe(PaymentVoid::STATUS_REQUESTED);

    $self = financeError(fn () => app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency)));
    expect($self->errorCode)->toBe('VOID_SELF_APPROVAL');

    $this->actingAs(financeUser($agency));
    $approved = app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    expect($approved->status)->toBe(PaymentVoid::STATUS_APPROVED)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_VOIDED)
        ->and($session->refresh()->expected_amount)->toBe('0.00')
        ->and(CashMovement::query()->where('type', CashMovement::TYPE_EXPENSE)->count())->toBe(1)
        ->and(BillingOrder::query()->where('order_id', $order->id)->sole()->payment_status)->toBe(BillingOrder::PAYMENT_REFUNDED)
        ->and(OutboxEvent::query()->where('event_type', 'payment.voided')->count())->toBe(1);
});

it('bloquea la anulación de un comprobante aceptado hasta emitir la nota de crédito', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency, '0.00');
    $payment = financePayment($agency, $order, '100.00');
    $payment->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $blocked = financeError(fn () => app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Customer cancelled',
    ], financeScope($agency)));
    expect($blocked->errorCode)->toBe('VOID_REQUIRES_CREDIT_NOTE');

    app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '100.00',
        'reason' => '01',
    ], financeScope($agency));

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Customer cancelled',
    ], financeScope($agency));

    $this->actingAs(financeUser($agency));
    app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    expect($payment->refresh()->status)->toBe(Payment::STATUS_VOIDED)
        ->and($session->refresh()->expected_amount)->toBe('0.00')
        ->and(CashMovement::query()->where('type', CashMovement::TYPE_EXPENSE)->count())->toBe(1);
});

it('rechaza una anulación y conserva el pago', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '100.00');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $this->actingAs(financeUser($agency));
    $rejected = app(RejectVoidUseCase::class)->execute($void->id, 'Payment is valid', financeScope($agency));

    expect($rejected->status)->toBe(PaymentVoid::STATUS_REJECTED)
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_REGISTERED);
});
