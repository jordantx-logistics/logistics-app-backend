<?php

require_once __DIR__.'/FinanceHelpers.php';

use Illuminate\Support\Facades\Storage;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Jobs\GenerateCreditNoteArtifactsJob;
use Src\Finance\Invoice\Jobs\GenerateInvoiceJob;
use Src\Finance\Invoice\Jobs\VoidInvoiceJob;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Shared\Models\OutboxEvent;

/**
 * Cobertura HTTP de notas de crédito y anulaciones.
 *
 * Los jobs del outbox se ejecutan a mano para fijar el estado del comprobante
 * antes de probar las rutas.
 */
it('emite una nota de crédito, la lista y descarga sus artefactos', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    $session = financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');

    $invoiceEvent = OutboxEvent::query()->where('event_type', 'payment.registered')->sole();
    app()->call([new GenerateInvoiceJob($invoiceEvent->id, $invoiceEvent->payload), 'handle']);

    $invoice = $payment->currentInvoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_ACCEPTED);

    $created = $this->postJson('/api/v1/finance/credit-notes', [
        'payment_id' => $payment->id,
        'amount' => 20,
        'reason' => '01',
        'note' => 'Partial refund',
    ])->assertCreated()
        ->assertJsonPath('data.status', CreditNote::STATUS_ISSUED)
        ->assertJsonPath('data.amount', '20.00')
        ->json('data');

    $creditNoteId = (int) $created['id'];

    $this->getJson('/api/v1/finance/credit-notes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $creditNoteId);

    $this->getJson("/api/v1/finance/credit-notes/{$creditNoteId}")
        ->assertOk()
        ->assertJsonPath('data.reason', '01');

    $noteEvent = OutboxEvent::query()->where('event_type', 'credit-note.issued')->sole();
    app()->call([new GenerateCreditNoteArtifactsJob($noteEvent->id, $noteEvent->payload), 'handle']);

    $note = CreditNote::query()->findOrFail($creditNoteId);

    expect($note->status)->toBe(CreditNote::STATUS_ACCEPTED)
        ->and($note->xml_path)->not->toBeNull()
        ->and($note->pdf_path)->not->toBeNull()
        ->and($note->cdr_path)->not->toBeNull();

    $this->getJson("/api/v1/finance/credit-notes/{$creditNoteId}/download/xml")->assertOk();
    $this->getJson("/api/v1/finance/credit-notes/{$creditNoteId}/download/pdf")->assertOk();
    $this->getJson("/api/v1/finance/credit-notes/{$creditNoteId}/download/cdr")->assertOk();

    expect($session->refresh()->expected_amount)->toBe('80.00')
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_REGISTERED);
});

it('valida los datos de una nota de crédito y su comprobante de origen', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '50.00');

    $this->postJson('/api/v1/finance/credit-notes', [
        'amount' => 10,
        'reason' => '99',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_id', 'reason']);

    $this->postJson('/api/v1/finance/credit-notes', [
        'payment_id' => $payment->id,
        'amount' => 10,
        'reason' => '01',
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'CREDIT_NOTE_INVOICE_NOT_ACCEPTED');

    $this->getJson('/api/v1/finance/credit-notes/999999')
        ->assertStatus(404)
        ->assertJsonPath('data.error_code', 'CREDIT_NOTE_NOT_FOUND');

    expect(CreditNote::query()->count())->toBe(0);
});

it('solicita, lista y aprueba una anulación con baja del comprobante', function () {
    Storage::fake('local');

    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '100.00');

    $created = $this->postJson('/api/v1/finance/voids', [
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ])->assertCreated()
        ->assertJsonPath('data.status', PaymentVoid::STATUS_REQUESTED)
        ->json('data');

    $voidId = (int) $created['id'];

    $this->getJson('/api/v1/finance/voids')
        ->assertOk()
        ->assertJsonPath('data.0.id', $voidId);

    $this->getJson("/api/v1/finance/voids/{$voidId}")
        ->assertOk()
        ->assertJsonPath('data.payment_id', $payment->id);

    $this->app['auth']->forgetGuards();
    $this->actingAs(financeUser($agency));

    $this->postJson("/api/v1/finance/voids/{$voidId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', PaymentVoid::STATUS_APPROVED);

    expect($payment->refresh()->status)->toBe(Payment::STATUS_VOIDED)
        ->and($payment->currentInvoice->refresh()->status)->toBe(Invoice::STATUS_VOIDED);

    $event = OutboxEvent::query()->where('event_type', 'payment.voided')->sole();
    app()->call([new VoidInvoiceJob($event->id, $event->payload), 'handle']);

    expect($payment->currentInvoice->refresh()->status)->toBe(Invoice::STATUS_VOIDED)
        ->and(OutboxEvent::query()->where('event_type', 'invoice.voided')->count())->toBe(1);
});

it('rechaza una anulación desde la API y conserva el pago', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    $order = financeOrder('100.00');
    financeSession($agency);
    $payment = financePayment($agency, $order, '100.00');

    $void = $this->postJson('/api/v1/finance/voids', [
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ])->assertCreated()->json('data');

    $this->app['auth']->forgetGuards();
    $this->actingAs(financeUser($agency));

    $this->postJson("/api/v1/finance/voids/{$void['id']}/reject", [
        'reason' => 'The payment is valid.',
    ])->assertOk()
        ->assertJsonPath('data.status', PaymentVoid::STATUS_REJECTED)
        ->assertJsonPath('data.rejection_reason', 'The payment is valid.');

    expect($payment->refresh()->status)->toBe(Payment::STATUS_REGISTERED);

    $this->postJson('/api/v1/finance/voids', [
        'payment_id' => $payment->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});
