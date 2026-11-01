<?php

require_once __DIR__.'/FinanceHelpers.php';

use Illuminate\Support\Facades\Storage;
use Src\Finance\Cash\Jobs\GenerateCashCloseReportJob;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Invoice\Jobs\GenerateInvoiceJob;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Shared\Models\OutboxEvent;

it('expone el flujo HTTP de caja, cobro, comprobante y reporte', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');

    $open = $this->postJson('/api/v1/finance/cash-sessions', ['opening_amount' => 100])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->json('data');

    $sessionId = (int) $open['id'];

    $this->getJson('/api/v1/finance/cash-sessions/current')
        ->assertOk()
        ->assertJsonPath('data.cash_session.id', $sessionId);

    $this->postJson('/api/v1/finance/cash-movements', [
        'type' => CashMovement::TYPE_EXPENSE,
        'amount' => 10,
        'note' => 'Courier advance',
        'reference_type' => 'courier',
        'reference_id' => 9,
    ])->assertCreated();

    $payment = $this->postJson('/api/v1/finance/payments', [
        'order_id' => $order->id,
        'amount' => 50,
        'payment_method' => Payment::METHOD_CASH,
        'idempotency_key' => 'api-payment-1',
    ])->assertCreated()
        ->assertJsonPath('data.status', Payment::STATUS_REGISTERED)
        ->json('data');

    $paymentId = (int) $payment['id'];
    $invoiceId = (int) $payment['invoice']['id'];

    $this->getJson("/api/v1/finance/orders/{$order->id}/payment-status")
        ->assertOk()
        ->assertJsonPath('data.paid_amount', '50.00')
        ->assertJsonPath('data.balance', '50.00')
        ->assertJsonPath('data.is_paid', false);

    $this->getJson('/api/v1/finance/payments')
        ->assertOk()
        ->assertJsonPath('data.0.id', $paymentId);

    $this->getJson("/api/v1/finance/payments/{$paymentId}")
        ->assertOk()
        ->assertJsonPath('data.id', $paymentId);

    $this->getJson('/api/v1/finance/cash-sessions')
        ->assertOk()
        ->assertJsonPath('data.0.id', $sessionId);

    $this->getJson("/api/v1/finance/cash-sessions/{$sessionId}")
        ->assertOk()
        ->assertJsonPath('data.expected_amount', '140.00');

    $this->getJson("/api/v1/finance/invoices/{$invoiceId}/download/xml")
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'INVOICE_ARTIFACT_NOT_READY');

    $event = OutboxEvent::query()->where('event_type', 'payment.registered')->sole();
    app()->call([new GenerateInvoiceJob($event->id, $event->payload), 'handle']);

    $xml = $this->getJson("/api/v1/finance/invoices/{$invoiceId}/download/xml")
        ->assertOk();

    expect((string) $xml->headers->get('content-disposition'))->toContain('attachment');

    $this->getJson("/api/v1/finance/invoices/{$invoiceId}/download/pdf")
        ->assertOk();

    $this->getJson('/api/v1/finance/invoices')
        ->assertOk()
        ->assertJsonPath('data.0.id', $invoiceId);

    $close = $this->postJson("/api/v1/finance/cash-sessions/{$sessionId}/close", [
        'closing_amount' => 140,
        'count' => [
            ['denomination' => 100, 'quantity' => 1],
            ['denomination' => 20, 'quantity' => 2],
        ],
    ])->assertOk()
        ->assertJsonPath('data.requires_approval', false)
        ->json('data');

    $closureId = (int) $close['closure']['id'];

    $this->getJson("/api/v1/finance/cash-closures/{$closureId}/report")
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'CASH_REPORT_NOT_READY');

    $closedEvent = OutboxEvent::query()->where('event_type', 'cash.closed')->sole();
    app()->call([new GenerateCashCloseReportJob($closedEvent->id, $closedEvent->payload), 'handle']);

    $this->getJson("/api/v1/finance/cash-closures/{$closureId}/report")
        ->assertOk()
        ->assertDownload('cash-session-'.$sessionId.'.pdf');

    $this->getJson('/api/v1/finance/cash-closures')
        ->assertOk()
        ->assertJsonPath('data.0.id', $closureId);
});

it('valida importes, métodos y llaves de idempotencia en las rutas', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);

    $this->postJson('/api/v1/finance/payments', ['order_id' => $order->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'payment_method', 'idempotency_key']);

    $this->postJson('/api/v1/finance/payments', [
        'order_id' => $order->id,
        'amount' => 10,
        'payment_method' => 'cheque',
        'idempotency_key' => 'bad-method',
    ])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);

    $this->postJson('/api/v1/finance/cash-sessions', ['opening_amount' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['opening_amount']);
});

it('crea, lista y actualiza series de comprobantes con validación de duplicados', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));

    $payload = [
        'agency_id' => $agency->id,
        'document_type' => 'boleta',
        'series' => 'B900',
        'issuer_ruc' => '20123456789',
        'issuer_name' => 'Logistics Test SAC',
        'is_default' => true,
    ];

    $created = $this->postJson('/api/v1/finance/invoice-series', $payload)
        ->assertCreated()
        ->assertJsonPath('data.series', 'B900')
        ->json('data');

    $this->postJson('/api/v1/finance/invoice-series', $payload)
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'INVOICE_SERIES_DUPLICATED');

    $this->getJson('/api/v1/finance/invoice-series')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/finance/invoice-series/{$created['id']}", [
        'issuer_trade_name' => 'Logistics',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.issuer_trade_name', 'Logistics')
        ->assertJsonPath('data.is_active', false);

    $this->postJson('/api/v1/finance/invoice-series', [
        ...$payload,
        'series' => 'b901',
    ])->assertUnprocessable()->assertJsonValidationErrors(['series']);
});

it('protege las rutas con los permisos de Finanzas', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');

    $cashier = financeCashier($agency);
    $this->app['auth']->forgetGuards();
    $this->actingAs($cashier);

    $this->getJson('/api/v1/finance/payments')->assertOk();
    $this->getJson('/api/v1/finance/invoices')->assertOk();
    $this->getJson('/api/v1/finance/cash-sessions')->assertOk();

    $this->postJson('/api/v1/finance/invoice-series', [
        'agency_id' => $agency->id,
        'document_type' => 'boleta',
        'series' => 'B902',
        'issuer_ruc' => '20123456789',
        'issuer_name' => 'Logistics Test SAC',
    ])->assertForbidden();

    $this->postJson("/api/v1/finance/invoices/{$payment->currentInvoice->id}/retry")
        ->assertForbidden();

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $this->postJson("/api/v1/finance/voids/{$void->id}/approve")->assertForbidden();

    $this->actingAs(financeUser($agency));
    $this->postJson("/api/v1/finance/voids/{$void->id}/reject", ['reason' => 'Valid payment'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('aplica el ámbito de agencia a los listados y a las búsquedas por id', function () {
    $agencyA = financeAgency('AMA01');
    $agencyB = financeAgency('BMB01');

    $userA = financeCashier($agencyA);
    $userB = financeCashier($agencyB);

    $orderA = financeOrder('100.00');
    $orderB = financeOrder('100.00');

    $this->actingAs($userA);
    financeSeries($agencyA);
    financeSession($agencyA);
    $paymentA = financePayment($agencyA, $orderA, '10.00');

    $this->actingAs($userB);
    financeSeries($agencyB);
    financeSession($agencyB);
    $paymentB = financePayment($agencyB, $orderB, '20.00');

    $this->app['auth']->forgetGuards();
    $this->actingAs($userA);

    $this->getJson('/api/v1/finance/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $paymentA->id);

    $this->getJson("/api/v1/finance/payments/{$paymentB->id}")
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'PAYMENT_NOT_FOUND');

    $this->getJson("/api/v1/finance/invoices/{$paymentB->currentInvoice->id}")
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'INVOICE_NOT_FOUND');

    $this->getJson('/api/v1/finance/cash-sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.agency_id', $agencyA->id);

    $this->getJson('/api/v1/finance/cash-sessions/current')
        ->assertOk()
        ->assertJsonPath('data.cash_session.agency_id', $agencyA->id);

    $sessionB = CashSession::query()->where('agency_id', $agencyB->id)->sole();
    $this->getJson("/api/v1/finance/cash-sessions/{$sessionB->id}")
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'CASH_SESSION_NOT_FOUND');
});

it('rechaza descargas de artefactos inexistentes o tipos desconocidos', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '10.00');
    $invoice = $payment->currentInvoice;

    $this->getJson("/api/v1/finance/invoices/{$invoice->id}/download/zip")
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'INVOICE_ARTIFACT_NOT_READY');

    $this->getJson('/api/v1/finance/invoices/999999')
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'INVOICE_NOT_FOUND');

    $this->getJson('/api/v1/finance/credit-notes/999999')
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'CREDIT_NOTE_NOT_FOUND');

    $this->getJson('/api/v1/finance/voids/999999')
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'VOID_NOT_FOUND');
});

it('devuelve 404 cuando no hay proyección de cobro para la orden', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    $order = financeOrder('100.00');

    $this->getJson("/api/v1/finance/orders/{$order->id}/payment-status")
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'BILLING_ORDER_NOT_FOUND');
});

it('usa el ámbito nacional del admin para listar todas las agencias', function () {
    $agencyA = financeAgency('ADA01');
    $agencyB = financeAgency('ADB01');

    $userA = financeCashier($agencyA);
    $userB = financeCashier($agencyB);

    $this->actingAs($userA);
    financeSeries($agencyA);
    financeSession($agencyA);
    financePayment($agencyA, financeOrder('100.00'), '10.00');

    $this->actingAs($userB);
    financeSeries($agencyB);
    financeSession($agencyB);
    financePayment($agencyB, financeOrder('100.00'), '20.00');

    $this->app['auth']->forgetGuards();
    $this->actingAs(financeUser($agencyA));

    $this->getJson('/api/v1/finance/payments')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/finance/cash-sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
