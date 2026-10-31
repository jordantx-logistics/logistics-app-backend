<?php

require_once __DIR__.'/FinanceHelpers.php';

use Illuminate\Support\Facades\Storage;
use Src\Finance\Cash\UseCases\ApproveCashClosureUseCase;
use Src\Finance\Cash\UseCases\CloseCashSessionUseCase;
use Src\Finance\Cash\UseCases\RejectCashClosureUseCase;
use Src\Finance\CreditNote\UseCases\IssueCreditNoteUseCase;
use Src\Finance\Invoice\Jobs\GenerateInvoiceJob;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\SunatTransmission;
use Src\Finance\Invoice\UseCases\RetrySunatTransmissionUseCase;
use Src\Finance\Payment\Jobs\MarkBillingOrderDeliveredJob;
use Src\Finance\Payment\Jobs\ProjectBillingOrderCancelledJob;
use Src\Finance\Payment\Jobs\ProjectBillingOrderCreatedJob;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Finance\Void\UseCases\ApproveVoidUseCase;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Logistics\Order\Jobs\ProjectPaymentRefundedJob;
use Src\Logistics\Order\Jobs\ProjectPaymentRegisteredJob;
use Src\Logistics\Order\Jobs\ProjectPaymentVoidedJob;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Models\OrderPaymentProjection;
use Src\Logistics\Order\UseCases\CancelOrderUseCase;
use Src\Shared\Models\OutboxEvent;

it('rechaza en SUNAT simulado, guarda el CDR y permite reintentar', function () {
    Storage::fake('local');
    config()->set('finance.sunat.mode', 'rejected');

    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('50.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');
    $invoice = $payment->currentInvoice;

    $event = OutboxEvent::query()->where('event_type', 'payment.registered')->sole();
    app()->call([new GenerateInvoiceJob($event->id, $event->payload), 'handle']);

    $invoice->refresh();

    expect($invoice->status)->toBe(Invoice::STATUS_REJECTED)
        ->and($invoice->sunat_code)->toBe('2335')
        ->and($invoice->cdr_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($invoice->cdr_path))->toBeTrue()
        ->and(SunatTransmission::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(OutboxEvent::query()->where('event_type', 'invoice.rejected')->count())->toBe(1);

    config()->set('finance.sunat.mode', 'accepted');

    $retried = app(RetrySunatTransmissionUseCase::class)->execute($invoice->id, financeScope($agency));

    expect($retried->status)->toBe(Invoice::STATUS_ACCEPTED)
        ->and($retried->sunat_code)->toBe('0')
        ->and(SunatTransmission::query()->where('invoice_id', $invoice->id)->count())->toBe(2)
        ->and(OutboxEvent::query()->where('event_type', 'invoice.accepted')->count())->toBe(1);
});

it('valida los estados y el ámbito al reintentar una transmisión', function () {
    $agency = financeAgency('RTY01');
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('50.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');
    $invoice = $payment->currentInvoice;

    $invoice->update(['status' => Invoice::STATUS_ACCEPTED]);
    $accepted = financeError(fn () => app(RetrySunatTransmissionUseCase::class)->execute($invoice->id, financeScope($agency)));
    expect($accepted->errorCode)->toBe('INVOICE_ALREADY_ACCEPTED');

    $invoice->update(['status' => Invoice::STATUS_VOIDED]);
    $voided = financeError(fn () => app(RetrySunatTransmissionUseCase::class)->execute($invoice->id, financeScope($agency)));
    expect($voided->errorCode)->toBe('INVOICE_VOIDED');

    $missing = financeError(fn () => app(RetrySunatTransmissionUseCase::class)->execute(999999, financeScope($agency)));
    expect($missing->errorCode)->toBe('INVOICE_NOT_FOUND');

    $invoice->update(['status' => Invoice::STATUS_REJECTED]);
    $otherAgency = financeAgency('RTY02');
    $outside = financeError(fn () => app(RetrySunatTransmissionUseCase::class)->execute($invoice->id, financeScope($otherAgency)));
    expect($outside->errorCode)->toBe('CASH_AGENCY_SCOPE');
});

it('proyecta order.created, delivery.completed y order.cancelled de forma idempotente', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    $order = financeOrder('100.00', [
        'ticket_number' => 'ORD-PROJ-1',
        'ticket_code' => 'ABCD1234',
        'origin_agency_id' => $agency->id,
        'destination_agency_id' => $agency->id,
    ]);

    $created = OutboxEvent::create([
        'event_type' => 'order.created',
        'payload' => [
            'order_id' => $order->id,
            'ticket_number' => $order->ticket_number,
            'ticket_code' => $order->ticket_code,
            'status' => $order->status,
            'origin_agency_id' => $order->origin_agency_id,
            'destination_agency_id' => $order->destination_agency_id,
            'sender_id' => $order->sender_id,
            'receiver_id' => $order->receiver_id,
            'payer_id' => $order->sender_id,
            'payer_name' => 'Payer',
            'customer_document' => ['type' => 'RUC', 'number' => '20123456789'],
            'amount_due' => '100.00',
            'currency' => 'PEN',
            'payment_responsibility' => 'sender',
        ],
    ]);

    app()->call([new ProjectBillingOrderCreatedJob($created->id, $created->payload), 'handle']);
    app()->call([new ProjectBillingOrderCreatedJob($created->id, $created->payload), 'handle']);

    $billing = BillingOrder::query()->where('order_id', $order->id)->sole();

    expect($billing->ticket_number)->toBe('ORD-PROJ-1')
        ->and($billing->amount_due)->toBe('100.00')
        ->and($billing->payment_status)->toBe(BillingOrder::PAYMENT_UNPAID)
        ->and($billing->customer_document_type)->toBe('RUC');

    $delivered = OutboxEvent::create([
        'event_type' => 'delivery.completed',
        'payload' => ['order_id' => $order->id, 'delivered_at' => now()->toIso8601String()],
    ]);

    app()->call([new MarkBillingOrderDeliveredJob($delivered->id, $delivered->payload), 'handle']);

    expect($billing->refresh()->ready_for_settlement)->toBeTrue()
        ->and($billing->delivered_at)->not->toBeNull();

    $cancelled = OutboxEvent::create([
        'event_type' => 'order.cancelled',
        'payload' => ['order_id' => $order->id, 'cancelled_at' => now()->toIso8601String()],
    ]);

    app()->call([new ProjectBillingOrderCancelledJob($cancelled->id, $cancelled->payload), 'handle']);

    expect($billing->refresh()->status)->toBe('cancelled');

    $missing = OutboxEvent::create(['event_type' => 'order.created', 'payload' => []]);
    app()->call([new ProjectBillingOrderCreatedJob($missing->id, $missing->payload), 'handle']);
});

it('mantiene la proyección de Core coherente con pagos, reembolsos y anulaciones', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency, '0.00');
    $payment = financePayment($agency, $order, '100.00');
    $invoice = $payment->currentInvoice;
    $invoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $registered = OutboxEvent::query()->where('event_type', 'payment.registered')->sole();
    app()->call([new ProjectPaymentRegisteredJob($registered->id, $registered->payload), 'handle']);
    app()->call([new ProjectPaymentRegisteredJob($registered->id, $registered->payload), 'handle']);

    $projection = OrderPaymentProjection::query()->where('order_id', $order->id)->sole();
    expect($projection->paid_amount)->toBe('100.00')
        ->and($projection->refunded_amount)->toBe('0.00')
        ->and($projection->status)->toBe(OrderPaymentProjection::STATUS_PAID)
        ->and($projection->netPaid())->toBe('100.00');

    $blocked = financeError(fn () => app(CancelOrderUseCase::class)->execute($order));
    expect($blocked->errorCode)->toBe('ORDER_HAS_PAYMENT');

    app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '40.00',
        'reason' => '01',
    ], financeScope($agency));

    $refundedEvent = OutboxEvent::query()->where('event_type', 'payment.refunded')->latest('id')->first();
    app()->call([new ProjectPaymentRefundedJob($refundedEvent->id, $refundedEvent->payload), 'handle']);
    app()->call([new ProjectPaymentRefundedJob($refundedEvent->id, $refundedEvent->payload), 'handle']);

    $projection->refresh();
    expect($projection->paid_amount)->toBe('100.00')
        ->and($projection->refunded_amount)->toBe('40.00')
        ->and($projection->status)->toBe(OrderPaymentProjection::STATUS_PARTIAL)
        ->and($projection->netPaid())->toBe('60.00');

    app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '60.00',
        'reason' => '01',
    ], financeScope($agency));

    $fullRefund = OutboxEvent::query()->where('event_type', 'payment.refunded')->latest('id')->first();
    app()->call([new ProjectPaymentRefundedJob($fullRefund->id, $fullRefund->payload), 'handle']);

    $projection->refresh();
    expect($projection->refunded_amount)->toBe('100.00')
        ->and($projection->status)->toBe(OrderPaymentProjection::STATUS_REFUNDED)
        ->and($projection->netPaid())->toBe('0.00');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Refunded and voided',
    ], financeScope($agency));

    $this->actingAs(financeUser($agency));
    app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    $voided = OutboxEvent::query()->where('event_type', 'payment.voided')->sole();
    app()->call([new ProjectPaymentVoidedJob($voided->id, $voided->payload), 'handle']);
    app()->call([new ProjectPaymentVoidedJob($voided->id, $voided->payload), 'handle']);

    $projection->refresh();
    expect($projection->paid_amount)->toBe('100.00')
        ->and($projection->refunded_amount)->toBe('100.00')
        ->and($projection->status)->toBe(OrderPaymentProjection::STATUS_REFUNDED)
        ->and($projection->netPaid())->toBe('0.00')
        ->and($session->refresh()->expected_amount)->toBe('0.00');

    $this->actingAs($requester);
    $cancelled = app(CancelOrderUseCase::class)->execute($order);
    expect($cancelled->status)->toBe(Order::STATUS_CANCELLED);
});

it('cubre los errores de aprobación y rechazo de cierres', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('20.00');
    $session = financeSession($agency, '5.00');
    financePayment($agency, $order, '20.00');

    $missing = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute(999999, financeScope($agency)));
    expect($missing->errorCode)->toBe('CASH_CLOSURE_NOT_FOUND');

    $pending = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '24.00'], financeScope($agency));

    $notPending = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute(999999, financeScope($agency)));
    expect($notPending->errorCode)->toBe('CASH_CLOSURE_NOT_FOUND');

    $this->actingAs(financeUser($agency));
    app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, 'Wrong count', financeScope($agency));

    $alreadyRejected = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency)));
    expect($alreadyRejected->errorCode)->toBe('CASH_CLOSURE_NOT_PENDING');

    $alreadyRejected = financeError(fn () => app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, 'Again', financeScope($agency)));
    expect($alreadyRejected->errorCode)->toBe('CASH_CLOSURE_NOT_PENDING');

    $missingReject = financeError(fn () => app(RejectCashClosureUseCase::class)->execute(999999, 'Reason', financeScope($agency)));
    expect($missingReject->errorCode)->toBe('CASH_CLOSURE_NOT_FOUND');
});

it('cubre las guardas de aprobación de anulaciones', function () {
    $agency = financeAgency('VDA01');
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency, '0.00');
    $payment = financePayment($agency, $order, '100.00');

    $missing = financeError(fn () => app(ApproveVoidUseCase::class)->execute(999999, financeScope($agency)));
    expect($missing->errorCode)->toBe('VOID_NOT_FOUND');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $this->actingAs(financeUser($agency));
    app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    $again = financeError(fn () => app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency)));
    expect($again->errorCode)->toBe('VOID_NOT_REQUESTED');

    $otherAgency = financeAgency('VDA02');
    $otherRequester = financeUser($otherAgency);
    $this->actingAs($otherRequester);
    financeSeries($otherAgency);
    $orderOther = financeOrder('100.00');
    financeSession($otherAgency, '0.00');
    $paymentOther = financePayment($otherAgency, $orderOther, '100.00');
    $voidOther = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $paymentOther->id,
        'reason' => 'Duplicate charge',
    ], financeScope($otherAgency));

    $this->actingAs(financeUser($agency));
    $outside = financeError(fn () => app(ApproveVoidUseCase::class)->execute($voidOther->id, financeScope($agency)));
    expect($outside->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $this->actingAs($requester);
    $paymentTwo = financePayment($agency, financeOrder('20.00'), '20.00');
    $voidTwo = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $paymentTwo->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $paymentTwo->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $this->actingAs(financeUser($agency));
    $requiresCredit = financeError(fn () => app(ApproveVoidUseCase::class)->execute($voidTwo->id, financeScope($agency)));
    expect($requiresCredit->errorCode)->toBe('VOID_REQUIRES_CREDIT_NOTE');

    $paymentThree = Payment::factory()->create([
        'order_id' => $order->id,
        'agency_id' => $agency->id,
        'cash_session_id' => null,
        'amount' => '10.00',
        'status' => Payment::STATUS_REGISTERED,
        'payment_method' => Payment::METHOD_POS,
    ]);

    $voidNoPayment = PaymentVoid::create([
        'order_id' => $order->id,
        'payment_id' => null,
        'reason' => 'No payment',
        'status' => PaymentVoid::STATUS_REQUESTED,
        'requested_by' => $paymentThree->created_by ?? $requester->id,
        'requested_at' => now(),
    ]);

    $this->actingAs(financeUser($agency));
    $noPayment = financeError(fn () => app(ApproveVoidUseCase::class)->execute($voidNoPayment->id, financeScope($agency)));
    expect($noPayment->errorCode)->toBe('VOID_PAYMENT_NOT_FOUND');

    $billingVoid = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $paymentThree->id,
        'reason' => 'Delete projection',
    ], financeScope($agency));

    BillingOrder::query()->where('order_id', $order->id)->delete();

    $this->actingAs(financeUser($agency));
    $withoutBilling = app(ApproveVoidUseCase::class)->execute($billingVoid->id, financeScope($agency));
    expect($withoutBilling->status)->toBe(PaymentVoid::STATUS_APPROVED);
});

it('no aprueba un cierre si la sesión dejó de estar abierta', function () {
    $agency = financeAgency('CLS04');
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $session = financeSession($agency, '0.00');

    $pending = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '1.00'], financeScope($agency));

    $session->update(['status' => 'closed']);

    $this->actingAs(financeUser($agency));
    $error = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency)));

    expect($error->errorCode)->toBe('CASH_SESSION_NOT_OPEN');
});

it('valida cierres fuera de ámbito, importes negativos y motivos vacíos', function () {
    $agency = financeAgency('CLS01');
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $session = financeSession($agency, '0.00');

    $negative = financeError(fn () => app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '-1.00'], financeScope($agency)));
    expect($negative->errorCode)->toBe('CASH_CLOSING_AMOUNT_INVALID');

    $other = financeAgency('CLS02');
    $outside = financeError(fn () => app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '0.00'], financeScope($other)));
    expect($outside->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $closed = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '0.00'], financeScope($agency));
    $notClosed = financeError(fn () => app(CloseCashSessionUseCase::class)->execute(999999, ['closing_amount' => '0.00'], financeScope($agency)));
    expect($notClosed->errorCode)->toBe('CASH_SESSION_NOT_FOUND');

    $otherSession = financeAgency('CLS03');
    $this->actingAs(financeUser($otherSession));
    $sessionOther = financeSession($otherSession, '0.00');

    $pending = app(CloseCashSessionUseCase::class)->execute($sessionOther->id, ['closing_amount' => '1.00'], financeScope($otherSession));

    $this->actingAs(financeUser($otherSession));
    $scopeError = financeError(fn () => app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, 'Reason', financeScope($agency)));
    expect($scopeError->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $blank = financeError(fn () => app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, '  ', financeScope($otherSession)));
    expect($blank->errorCode)->toBe('CASH_CLOSURE_REJECTION_REASON_REQUIRED');

    $approvedScope = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency)));
    expect($approvedScope->errorCode)->toBe('CASH_AGENCY_SCOPE');
});
