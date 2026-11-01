<?php

require_once __DIR__.'/FinanceHelpers.php';

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\Services\CashierAgencyResolver;
use Src\Finance\Cash\UseCases\ApproveCashClosureUseCase;
use Src\Finance\Cash\UseCases\CloseCashSessionUseCase;
use Src\Finance\Cash\UseCases\OpenCashSessionUseCase;
use Src\Finance\Cash\UseCases\RegisterCashMovementUseCase;
use Src\Finance\Cash\UseCases\RejectCashClosureUseCase;
use Src\Finance\CreditNote\UseCases\IssueCreditNoteUseCase;
use Src\Finance\Invoice\Adapters\FakeSunatGateway;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\UseCases\RegisterPaymentUseCase;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Finance\Void\UseCases\ApproveVoidUseCase;
use Src\Finance\Void\UseCases\RejectVoidUseCase;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Shared\Models\AuditLog;

/*
|--------------------------------------------------------------------------
| Finanzas (Fase 5): reglas de caja y anulación, y auditoría
|--------------------------------------------------------------------------
|
| Criterios 1, 6 y 9 de la fase. La concurrencia (criterio 2) necesita conexiones
| reales y vive en `scripts/finance-concurrency-check.php`.
|
*/

function financeAudit(string $action, int $entityId): AuditLog
{
    return AuditLog::query()->where('action', $action)->where('entity_id', $entityId)->sole();
}

it('audita la anulación aprobada con usuario, motivo y valores antes/después', function () {
    $agency = financeAgency();
    $requester = financeUser($agency);
    $this->actingAs($requester);
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $requested = financeAudit('void.requested', $void->id);

    expect($requested->actor_id)->toBe($requester->id)
        ->and($requested->before)->toBe(['payment_status' => Payment::STATUS_REGISTERED])
        ->and($requested->after['reason'])->toBe('Duplicate charge');

    $approver = financeUser($agency);
    $this->actingAs($approver);
    app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    $approved = financeAudit('void.approved', $void->id);

    expect($approved->actor_id)->toBe($approver->id)
        ->and($approved->before)->toBe([
            'payment_status' => Payment::STATUS_REGISTERED,
            'void_status' => PaymentVoid::STATUS_REQUESTED,
        ])
        ->and($approved->after['payment_status'])->toBe(Payment::STATUS_VOIDED)
        ->and($approved->after['void_status'])->toBe(PaymentVoid::STATUS_APPROVED)
        ->and($approved->after['reason'])->toBe('Duplicate charge');
});

it('audita la anulación rechazada con usuario y motivo', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');

    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));

    $reviewer = financeUser($agency);
    $this->actingAs($reviewer);
    app(RejectVoidUseCase::class)->execute($void->id, 'Payment is valid', financeScope($agency));

    $rejected = financeAudit('void.rejected', $void->id);

    expect($rejected->actor_id)->toBe($reviewer->id)
        ->and($rejected->before)->toBe(['status' => PaymentVoid::STATUS_REQUESTED])
        ->and($rejected->after)->toBe(['status' => PaymentVoid::STATUS_REJECTED, 'reason' => 'Payment is valid']);
});

it('audita la nota de crédito con usuario, motivo y valores antes/después', function () {
    $agency = financeAgency();
    $user = financeUser($agency);
    $this->actingAs($user);
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');
    $payment->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $note = app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '100.00',
        'reason' => '01',
    ], financeScope($agency));

    $issued = financeAudit('credit_note.issued', $note->id);

    expect($issued->actor_id)->toBe($user->id)
        ->and($issued->before)->toBe(['payment_status' => Payment::STATUS_REGISTERED, 'refunded_total' => '0.00'])
        ->and($issued->after['payment_status'])->toBe(Payment::STATUS_REFUNDED)
        ->and($issued->after['reason'])->toBe('01');
});

it('audita el cierre con descuadre al solicitarlo, aprobarlo y rechazarlo', function () {
    $agency = financeAgency();
    $cashier = financeUser($agency);
    $this->actingAs($cashier);
    $session = financeSession($agency, '50.00');

    $first = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '48.00'], financeScope($agency));

    $requested = financeAudit('cash.closure.requested', $first['closure']->id);

    expect($requested->actor_id)->toBe($cashier->id)
        ->and($requested->after['difference'])->toBe('-2.00');

    $supervisor = financeUser($agency);
    $this->actingAs($supervisor);
    app(RejectCashClosureUseCase::class)->execute($first['closure']->id, 'Count again', financeScope($agency));

    expect(financeAudit('cash.closure.rejected', $first['closure']->id)->actor_id)->toBe($supervisor->id);

    $this->actingAs($cashier);
    $second = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '48.00'], financeScope($agency));

    $this->actingAs($supervisor);
    app(ApproveCashClosureUseCase::class)->execute($second['closure']->id, financeScope($agency));

    expect(financeAudit('cash.closure.approved', $second['closure']->id)->actor_id)->toBe($supervisor->id);
});

it('no aprueba un cierre si la sesión cambió después del conteo', function () {
    $agency = financeAgency();
    $cashier = financeUser($agency);
    $this->actingAs($cashier);
    financeSeries($agency);
    $session = financeSession($agency, '50.00');

    $pending = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '48.00'], financeScope($agency));

    // La sesión sigue abierta: el cobro entra y deja viejo el esperado del cierre.
    financePayment($agency, financeOrder('10.00'), '10.00');

    $supervisor = financeUser($agency);
    $this->actingAs($supervisor);

    $outdated = financeError(fn () => app(ApproveCashClosureUseCase::class)->execute($pending['closure']->id, financeScope($agency)));

    expect($outdated->errorCode)->toBe('CASH_CLOSURE_OUTDATED')
        ->and($session->refresh()->status)->toBe(CashSession::STATUS_OPEN)
        ->and($pending['closure']->refresh()->status)->toBe(CashClosure::STATUS_PENDING);

    app(RejectCashClosureUseCase::class)->execute($pending['closure']->id, 'Payment after count', financeScope($agency));

    $this->actingAs($cashier);
    $balanced = app(CloseCashSessionUseCase::class)->execute($session->id, ['closing_amount' => '60.00'], financeScope($agency));

    expect($balanced['requires_approval'])->toBeFalse()
        ->and($session->refresh()->status)->toBe(CashSession::STATUS_CLOSED);
});

it('valida la apertura de caja', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));

    $outOfScope = financeError(fn () => app(OpenCashSessionUseCase::class)->execute(
        ['opening_amount' => '10.00'],
        financeScope(financeAgency('OTHER')),
    ));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $negative = financeError(fn () => financeSession($agency, '-1.00'));
    expect($negative->errorCode)->toBe('CASH_OPENING_AMOUNT_INVALID');

    financeSession($agency);
    $twice = financeError(fn () => financeSession($agency));
    expect($twice->errorCode)->toBe('CASH_SINGLE_OPEN_SESSION');
});

it('resuelve una apertura que pierde la carrera como sesión ya abierta', function () {
    $agency = financeAgency();
    $user = financeUser($agency);
    $this->actingAs($user);

    // La otra apertura entra justo después de que esta comprobara que no había.
    CashSession::creating(function () use ($agency, $user): void {
        DB::table('cash_sessions')->insert([
            'agency_id' => $agency->id,
            'cashier_id' => $user->id,
            'status' => CashSession::STATUS_OPEN,
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
    });

    try {
        $error = financeError(fn () => financeSession($agency));
    } finally {
        CashSession::flushEventListeners();
    }

    expect($error->errorCode)->toBe('CASH_SINGLE_OPEN_SESSION');
});

it('la base admite una sola sesión abierta por cajero, pero todo su historial', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    $first = financeSession($agency, '10.00');

    app(CloseCashSessionUseCase::class)->execute($first->id, ['closing_amount' => '10.00'], financeScope($agency));
    $second = financeSession($agency, '10.00');

    expect($second->id)->not->toBe($first->id);

    expect(fn () => DB::table('cash_sessions')->insert([
        'agency_id' => $agency->id,
        'cashier_id' => $second->cashier_id,
        'status' => CashSession::STATUS_OPEN,
        'opening_amount' => '0.00',
        'opened_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('exige un cajero autenticado y con agencia para operar caja', function () {
    $unauthenticated = financeError(fn () => app(CashierAgencyResolver::class)->resolve());
    expect($unauthenticated->errorCode)->toBe('CASH_AGENCY_REQUIRED');

    $this->actingAs(User::factory()->create());

    $noAgency = financeError(fn () => app(CashierAgencyResolver::class)->resolve());
    expect($noAgency->errorCode)->toBe('CASH_AGENCY_REQUIRED');
});

it('valida los movimientos manuales de caja', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    $useCase = app(RegisterCashMovementUseCase::class);
    $valid = ['type' => 'expense', 'amount' => '5.00', 'note' => 'Supplies'];

    $noSession = financeError(fn () => $useCase->execute($valid, financeScope($agency)));
    expect($noSession->errorCode)->toBe('CASH_SESSION_OPEN_REQUIRED');

    $session = financeSession($agency, '50.00');

    $outOfScope = financeError(fn () => $useCase->execute($valid, financeScope(financeAgency('OTHER'))));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $type = financeError(fn () => $useCase->execute([...$valid, 'type' => 'income'], financeScope($agency)));
    expect($type->errorCode)->toBe('CASH_MOVEMENT_TYPE_INVALID');

    $amount = financeError(fn () => $useCase->execute([...$valid, 'amount' => '0'], financeScope($agency)));
    expect($amount->errorCode)->toBe('CASH_MOVEMENT_AMOUNT_INVALID');

    $note = financeError(fn () => $useCase->execute([...$valid, 'note' => '  '], financeScope($agency)));
    expect($note->errorCode)->toBe('CASH_MOVEMENT_NOTE_REQUIRED');

    $useCase->execute($valid, financeScope($agency));
    expect($session->refresh()->expected_amount)->toBe('45.00');
});

it('rechaza el cobro fuera de ámbito o de una orden inexistente', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSession($agency);

    $outOfScope = financeError(fn () => app(RegisterPaymentUseCase::class)->execute([
        'order_id' => financeOrder('10.00')->id,
        'amount' => '10.00',
        'payment_method' => Payment::METHOD_CASH,
        'idempotency_key' => 'out-of-scope',
    ], financeScope(financeAgency('OTHER'))));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $missing = financeError(fn () => app(RegisterPaymentUseCase::class)->execute([
        'order_id' => 999999,
        'amount' => '10.00',
        'payment_method' => Payment::METHOD_CASH,
        'idempotency_key' => 'missing-order',
    ], financeScope($agency)));
    expect($missing->errorCode)->toBe('ORDER_NOT_FOUND');
});

it('valida la solicitud de anulación', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');
    $useCase = app(RequestVoidUseCase::class);
    $valid = ['payment_id' => $payment->id, 'reason' => 'Duplicate charge'];

    $missing = financeError(fn () => $useCase->execute([...$valid, 'payment_id' => 999999], financeScope($agency)));
    expect($missing->errorCode)->toBe('VOID_PAYMENT_NOT_FOUND');

    $outOfScope = financeError(fn () => $useCase->execute($valid, financeScope(financeAgency('OTHER'))));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $noReason = financeError(fn () => $useCase->execute([...$valid, 'reason' => '  '], financeScope($agency)));
    expect($noReason->errorCode)->toBe('VOID_REASON_REQUIRED');

    $void = $useCase->execute($valid, financeScope($agency));

    $twice = financeError(fn () => $useCase->execute($valid, financeScope($agency)));
    expect($twice->errorCode)->toBe('VOID_ALREADY_EXISTS');

    $this->actingAs(financeUser($agency));
    app(ApproveVoidUseCase::class)->execute($void->id, financeScope($agency));

    $voided = financeError(fn () => $useCase->execute($valid, financeScope($agency)));
    expect($voided->errorCode)->toBe('VOID_ALREADY_APPROVED');

    $credit = financeError(fn () => app(IssueCreditNoteUseCase::class)->execute([
        'payment_id' => $payment->id,
        'amount' => '10.00',
        'reason' => '01',
    ], financeScope($agency)));
    expect($credit->errorCode)->toBe('CREDIT_NOTE_PAYMENT_VOIDED');
});

it('valida el rechazo de una anulación', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');
    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Duplicate charge',
    ], financeScope($agency));
    $useCase = app(RejectVoidUseCase::class);

    $missing = financeError(fn () => $useCase->execute(999999, 'Reason', financeScope($agency)));
    expect($missing->errorCode)->toBe('VOID_NOT_FOUND');

    $outOfScope = financeError(fn () => $useCase->execute($void->id, 'Reason', financeScope(financeAgency('OTHER'))));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $noReason = financeError(fn () => $useCase->execute($void->id, '  ', financeScope($agency)));
    expect($noReason->errorCode)->toBe('VOID_REJECTION_REASON_REQUIRED');

    $useCase->execute($void->id, 'Reason', financeScope($agency));

    $twice = financeError(fn () => $useCase->execute($void->id, 'Reason', financeScope($agency)));
    expect($twice->errorCode)->toBe('VOID_NOT_REQUESTED');
});

it('valida el alcance y el importe de la nota de crédito', function () {
    $agency = financeAgency();
    $this->actingAs(financeUser($agency));
    financeSeries($agency);
    financeSession($agency);
    $payment = financePayment($agency, financeOrder('100.00'), '100.00');
    $payment->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);
    $useCase = app(IssueCreditNoteUseCase::class);
    $valid = ['payment_id' => $payment->id, 'amount' => '10.00', 'reason' => '01'];

    $missing = financeError(fn () => $useCase->execute([...$valid, 'payment_id' => 999999], financeScope($agency)));
    expect($missing->errorCode)->toBe('CREDIT_NOTE_PAYMENT_NOT_FOUND');

    $outOfScope = financeError(fn () => $useCase->execute($valid, financeScope(financeAgency('OTHER'))));
    expect($outOfScope->errorCode)->toBe('CASH_AGENCY_SCOPE');

    $amount = financeError(fn () => $useCase->execute([...$valid, 'amount' => '0'], financeScope($agency)));
    expect($amount->errorCode)->toBe('CREDIT_NOTE_AMOUNT_INVALID');
});

it('responde 404 y no 500 ante un id no numérico', function (string $uri) {
    $this->actingAs(financeUser(financeAgency()));

    $this->getJson($uri)->assertNotFound();
})->with([
    '/api/v1/finance/payments/abc',
    '/api/v1/finance/orders/abc/payment-status',
    '/api/v1/finance/cash-sessions/abc',
    '/api/v1/finance/cash-closures/abc/report',
    '/api/v1/finance/credit-notes/abc',
    '/api/v1/finance/credit-notes/abc/download/pdf',
    '/api/v1/finance/invoices/abc',
    '/api/v1/finance/invoices/abc/download/pdf',
    '/api/v1/finance/voids/abc',
]);

it('selecciona el gateway SUNAT por configuración y no cae al simulado', function () {
    config()->set('finance.sunat.gateway', 'fake');
    app()->forgetInstance(SunatGateway::class);
    expect(app(SunatGateway::class))->toBeInstanceOf(FakeSunatGateway::class);

    config()->set('finance.sunat.gateway', 'unknown');
    app()->forgetInstance(SunatGateway::class);
    expect(fn () => app(SunatGateway::class))->toThrow(InvalidArgumentException::class, 'Unknown SUNAT_GATEWAY: unknown');
});
