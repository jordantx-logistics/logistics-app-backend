<?php

/**
 * Criterio 2 de la Fase 5: sin dobles movimientos bajo cobro simultáneo.
 *
 * Uso (MySQL desechable del perfil `concurrency` de docker-compose):
 *   docker compose --profile concurrency up -d --wait mysql-concurrency
 *   docker compose exec -e DB_HOST=mysql-concurrency -e DB_DATABASE=logistics_concurrency \
 *     -e DB_USERNAME=root -e DB_PASSWORD=root app php scripts/finance-concurrency-check.php [rondas]
 *   docker compose --profile concurrency rm -sf mysql-concurrency
 *
 * La suite va con SQLite en memoria, que no tiene conexiones concurrentes ni
 * bloqueos de fila, así que esto corre aparte contra MySQL: un conjunto de
 * procesos reales, cada uno con su conexión, ejecuta los casos de uso a la vez.
 *
 * Hace `migrate:fresh`, así que se niega a correr si la base no termina en
 * `_concurrency`. Termina con 1 si se rompe un invariante o si un caso de uso
 * falla con algo que no sea un `BusinessRuleException` (para el cajero, un 500).
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Src\Customer\Models\Customer;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\UseCases\ApproveCashClosureUseCase;
use Src\Finance\Cash\UseCases\CloseCashSessionUseCase;
use Src\Finance\Cash\UseCases\OpenCashSessionUseCase;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\CreditNote\UseCases\IssueCreditNoteUseCase;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Finance\Payment\Models\BillingOrder;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\UseCases\RegisterPaymentUseCase;
use Src\Finance\Void\UseCases\ApproveVoidUseCase;
use Src\Finance\Void\UseCases\RequestVoidUseCase;
use Src\Logistics\Order\Models\Order;
use Src\Organization\Models\Agency;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

const WORKERS = 8;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support/WorkerPool.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')
    || config('database.default') !== 'mysql'
    || ! str_ends_with((string) config('database.connections.mysql.database'), '_concurrency')) {
    fwrite(STDERR, "Solo corre fuera de producción, en MySQL y en una base `*_concurrency`.\n");
    exit(1);
}

// La caché en memoria evita que `migrate:fresh` vacíe la de permisos de la app.
config(['cache.default' => 'array']);

if (($argv[1] ?? null) === '--worker') {
    WorkerPool::serve(execute(...));
    exit(0);
}

Artisan::call('migrate:fresh', ['--force' => true]);

$rounds = max(1, (int) ($argv[1] ?? 3));
$pool = new WorkerPool(__FILE__, WORKERS);

$agency = Agency::factory()->create([
    'code' => 'CONC1',
    'name' => 'Agencia concurrencia',
    'type' => 'branch',
    'ubigeo' => '150101',
    'timezone' => 'America/Lima',
    'is_active' => true,
]);

InvoiceSeries::factory()->create([
    'agency_id' => $agency->id,
    'document_type' => 'boleta',
    'series' => 'B001',
    'issuer_ruc' => '20123456789',
    'issuer_name' => 'Concurrency SAC',
    'is_active' => true,
    'is_default' => true,
]);

$failures = 0;

for ($round = 1; $round <= $rounds; $round++) {
    echo "Ronda {$round}/{$rounds}\n";

    foreach ([
        'mismo saldo, sin proyección previa' => fn () => scenarioSameBalance($pool, $agency, false),
        'mismo saldo, con proyección previa' => fn () => scenarioSameBalance($pool, $agency, true),
        'misma idempotency_key' => fn () => scenarioSameKey($pool, $agency),
        'cobros simultáneos en una sesión' => fn () => scenarioSameSession($pool, $agency),
        'cierre contra cobros' => fn () => scenarioCloseAgainstPayments($pool, $agency),
        'nota de crédito contra cobros' => fn () => scenarioCreditNoteAgainstPayments($pool, $agency),
        'doble cierre' => fn () => scenarioDoubleClose($pool, $agency),
        'doble apertura' => fn () => scenarioDoubleOpen($pool, $agency),
        'doble aprobación de cierre' => fn () => scenarioDoubleClosureApproval($pool, $agency),
        'notas de crédito sobre un pago' => fn () => scenarioCreditNotes($pool, $agency),
        'doble aprobación de anulación' => fn () => scenarioDoubleVoidApproval($pool, $agency),
    ] as $name => $scenario) {
        $problems = $scenario();
        $failures += count($problems);

        printf("  %-40s %s\n", $name, $problems === [] ? 'OK' : 'FALLO');

        foreach ($problems as $problem) {
            echo "    - {$problem}\n";
        }
    }
}

$pool->stop();

echo $failures === 0 ? "Sin dobles movimientos.\n" : "{$failures} invariante(s) rotos.\n";

exit($failures === 0 ? 0 : 1);

// Escenarios: cada uno devuelve la lista de invariantes rotos.

function scenarioSameBalance(WorkerPool $pool, Agency $agency, bool $projected): array
{
    $cashiers = cashiers($agency, WORKERS);
    $order = order('100.00');

    if ($projected) {
        Auth::setUser($cashiers[0]);
        app(RegisterPaymentUseCase::class)->execute([
            'order_id' => $order->id,
            'amount' => '1.00',
            'payment_method' => Payment::METHOD_CASH,
            'idempotency_key' => uniqid('seed-', true),
        ], scope($agency));
    }

    $replies = $pool->run(array_map(fn (User $cashier): array => [
        'op' => 'pay',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'order' => $order->id,
        'amount' => '60.00',
        'key' => uniqid('pay-', true),
    ], $cashiers));

    $problems = unexpected($replies, ['PAYMENT_AMOUNT_EXCEEDS_BALANCE']);
    $accepted = okCount($replies);

    if ($accepted !== 1) {
        $problems[] = "se aceptaron {$accepted} cobros de 60.00 sobre un saldo que admite uno";
    }

    $paid = centsOf(Payment::query()->where('order_id', $order->id)->pluck('amount'));
    $billing = BillingOrder::query()->where('order_id', $order->id)->first();

    if ($paid > 10000) {
        $problems[] = 'lo cobrado supera el total de la orden: '.($paid / 100);
    }

    if ($billing === null || centsOf([$billing->paid_amount]) !== $paid) {
        $problems[] = 'billing_orders.paid_amount no coincide con los pagos';
    }

    return $problems;
}

function scenarioSameKey(WorkerPool $pool, Agency $agency): array
{
    $cashiers = cashiers($agency, WORKERS);
    $order = order('10.00');
    $key = uniqid('same-', true);

    $replies = $pool->run(array_map(fn (User $cashier): array => [
        'op' => 'pay',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'order' => $order->id,
        'amount' => '10.00',
        'key' => $key,
    ], $cashiers));

    $problems = unexpected($replies, []);
    $payments = Payment::query()->where('idempotency_key', $key)->pluck('id');

    if ($payments->count() !== 1) {
        $problems[] = "la misma clave creó {$payments->count()} pagos";
    }

    if (okCount($replies) !== WORKERS) {
        $problems[] = 'un reintento con la misma clave no devolvió el pago original';
    }

    if (Invoice::query()->where('order_id', $order->id)->count() !== 1) {
        $problems[] = 'la misma clave duplicó el comprobante';
    }

    if (CashMovement::query()->where('reference_type', 'payment')->whereIn('reference_id', $payments)->count() !== 1) {
        $problems[] = 'la misma clave duplicó el ingreso de caja';
    }

    return $problems;
}

function scenarioSameSession(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $session = CashSession::query()->open()->where('cashier_id', $cashier->id)->sole();

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'pay',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'order' => order('10.00')->id,
        'amount' => '10.00',
        'key' => uniqid('pay-', true),
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);
    $incomes = CashMovement::query()
        ->where('cash_session_id', $session->id)
        ->where('type', CashMovement::TYPE_INCOME)
        ->count();

    if ($incomes !== WORKERS) {
        $problems[] = 'se esperaban '.WORKERS." ingresos y hay {$incomes}";
    }

    $expected = centsOf([$session->opening_amount]) + 1000 * WORKERS;

    if (centsOf([$session->refresh()->expected_amount]) !== $expected) {
        $problems[] = "expected_amount {$session->expected_amount} no incluye todos los cobros";
    }

    return $problems;
}

function scenarioCloseAgainstPayments(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $session = CashSession::query()->open()->where('cashier_id', $cashier->id)->sole();

    $commands = array_map(fn (): array => [
        'op' => 'pay',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'order' => order('10.00')->id,
        'amount' => '10.00',
        'key' => uniqid('pay-', true),
    ], range(1, WORKERS - 1));

    $commands[] = [
        'op' => 'close',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'session' => $session->id,
        'closing' => (string) $session->opening_amount,
    ];

    $replies = $pool->run($commands);
    $problems = unexpected($replies, ['CASH_SESSION_OPEN_REQUIRED']);

    $session->refresh();
    $incomes = CashMovement::query()
        ->where('cash_session_id', $session->id)
        ->where('type', CashMovement::TYPE_INCOME);

    if ($session->status === CashSession::STATUS_CLOSED) {
        $closing = CashMovement::query()
            ->where('cash_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CLOSING)
            ->sole();

        if ((clone $incomes)->where('id', '>', $closing->id)->exists()) {
            $problems[] = 'entró un cobro después del cierre';
        }
    }

    $expected = centsOf([$session->opening_amount]) + centsOf($incomes->pluck('amount'));

    if (centsOf([$session->expected_amount]) !== $expected) {
        $problems[] = "expected_amount {$session->expected_amount} no cuadra con los movimientos";
    }

    return $problems;
}

function scenarioCreditNoteAgainstPayments(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $session = CashSession::query()->open()->where('cashier_id', $cashier->id)->sole();
    $credited = payment($cashier, $agency, '50.00');
    $credited->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $commands = array_map(fn (): array => [
        'op' => 'pay',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'order' => order('10.00')->id,
        'amount' => '10.00',
        'key' => uniqid('pay-', true),
    ], range(1, WORKERS - 1));

    $commands[] = [
        'op' => 'credit',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'payment' => $credited->id,
        'amount' => '20.00',
    ];

    $problems = unexpected($pool->run($commands), []);

    // 100.00 de fondo + 50.00 + 7 cobros de 10.00 − 20.00 de la nota.
    if (centsOf([$session->refresh()->expected_amount]) !== 20000) {
        $problems[] = "expected_amount {$session->expected_amount} no incluye todos los movimientos (200.00)";
    }

    return $problems;
}

function scenarioDoubleClose(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $session = CashSession::query()->open()->where('cashier_id', $cashier->id)->sole();

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'close',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'session' => $session->id,
        'closing' => (string) $session->opening_amount,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['CASH_SESSION_ALREADY_CLOSED']);

    if (okCount($replies) !== 1) {
        $problems[] = 'la sesión se cerró más de una vez';
    }

    if (CashClosure::query()->where('cash_session_id', $session->id)->count() !== 1) {
        $problems[] = 'el doble cierre duplicó cash_closures';
    }

    if (CashMovement::query()->where('cash_session_id', $session->id)->where('type', CashMovement::TYPE_CLOSING)->count() !== 1) {
        $problems[] = 'el doble cierre duplicó el movimiento closing';
    }

    return $problems;
}

function scenarioDoubleOpen(WorkerPool $pool, Agency $agency): array
{
    $cashier = user($agency);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'open',
        'user' => $cashier->id,
        'agency' => $agency->id,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['CASH_SINGLE_OPEN_SESSION']);
    $open = CashSession::query()->open()->where('cashier_id', $cashier->id)->count();

    if ($open !== 1) {
        $problems[] = "el cajero quedó con {$open} sesiones abiertas";
    }

    return $problems;
}

function scenarioDoubleClosureApproval(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $session = CashSession::query()->open()->where('cashier_id', $cashier->id)->sole();

    Auth::setUser($cashier);
    $closure = app(CloseCashSessionUseCase::class)->execute($session->id, [
        'closing_amount' => '1.00',
    ], scope($agency))['closure'];

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'approve_closure',
        'user' => user($agency)->id,
        'agency' => $agency->id,
        'closure' => $closure->id,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['CASH_CLOSURE_NOT_PENDING']);

    if (okCount($replies) !== 1) {
        $problems[] = 'el cierre se aprobó más de una vez';
    }

    if (CashMovement::query()->where('cash_session_id', $session->id)->where('type', CashMovement::TYPE_CLOSING)->count() !== 1) {
        $problems[] = 'la doble aprobación duplicó el movimiento closing';
    }

    if (OutboxEvent::query()->where('event_type', 'cash.closed')->get()->where('payload.cash_closure_id', $closure->id)->count() !== 1) {
        $problems[] = 'la doble aprobación duplicó cash.closed';
    }

    return $problems;
}

function scenarioCreditNotes(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $payment = payment($cashier, $agency, '100.00');
    $payment->currentInvoice->update(['status' => Invoice::STATUS_ACCEPTED]);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'credit',
        'user' => $cashier->id,
        'agency' => $agency->id,
        'payment' => $payment->id,
        'amount' => '60.00',
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['CREDIT_NOTE_AMOUNT_EXCEEDS_PAYMENT']);
    $accepted = okCount($replies);

    if ($accepted !== 1) {
        $problems[] = "se emitieron {$accepted} notas de 60.00 sobre un pago de 100.00";
    }

    if (centsOf(CreditNote::query()->where('payment_id', $payment->id)->pluck('amount')) > 10000) {
        $problems[] = 'las notas de crédito superan el pago';
    }

    return $problems;
}

function scenarioDoubleVoidApproval(WorkerPool $pool, Agency $agency): array
{
    $cashier = cashiers($agency, 1)[0];
    $payment = payment($cashier, $agency, '100.00');

    Auth::setUser($cashier);
    $void = app(RequestVoidUseCase::class)->execute([
        'payment_id' => $payment->id,
        'reason' => 'Concurrency check',
    ], scope($agency));

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'approve_void',
        'user' => user($agency)->id,
        'agency' => $agency->id,
        'void' => $void->id,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['VOID_NOT_REQUESTED']);

    if (okCount($replies) !== 1) {
        $problems[] = 'la anulación se aprobó más de una vez';
    }

    $expenses = CashMovement::query()
        ->where('reference_type', 'void')
        ->where('reference_id', $void->id)
        ->count();

    if ($expenses !== 1) {
        $problems[] = "la anulación dejó {$expenses} egresos de caja";
    }

    if (centsOf([BillingOrder::query()->where('order_id', $payment->order_id)->value('refunded_amount')]) !== 10000) {
        $problems[] = 'la anulación duplicó el reembolso en billing_orders';
    }

    return $problems;
}

// Datos de apoyo.

function user(Agency $agency): User
{
    $user = User::factory()->create();
    $user->agencies()->attach($agency->id, ['is_primary' => true]);

    return $user;
}

/**
 * @return list<User> Cajeros, cada uno con su sesión abierta y 100.00 de fondo.
 */
function cashiers(Agency $agency, int $count): array
{
    return array_map(function () use ($agency): User {
        $cashier = user($agency);

        Auth::setUser($cashier);
        app(OpenCashSessionUseCase::class)->execute(['opening_amount' => '100.00'], scope($agency));

        return $cashier;
    }, range(1, $count));
}

function order(string $total): Order
{
    $customer = Customer::factory()->create();

    return Order::factory()->create([
        'sender_id' => $customer->id,
        'receiver_id' => $customer->id,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'payment_responsibility' => Order::PAYMENT_SENDER,
        'total_amount' => $total,
        'status' => Order::STATUS_CONFIRMED,
    ]);
}

function payment(User $cashier, Agency $agency, string $amount): Payment
{
    Auth::setUser($cashier);

    return app(RegisterPaymentUseCase::class)->execute([
        'order_id' => order($amount)->id,
        'amount' => $amount,
        'payment_method' => Payment::METHOD_CASH,
        'idempotency_key' => uniqid('pay-', true),
    ], scope($agency));
}

function scope(Agency $agency): AgencyScope
{
    return AgencyScope::forAgencies([$agency->id]);
}

/**
 * @param  iterable<mixed>  $amounts
 */
function centsOf(iterable $amounts): int
{
    $cents = 0;

    foreach ($amounts as $amount) {
        $cents += (int) round(((float) $amount) * 100);
    }

    return $cents;
}

function okCount(array $replies): int
{
    return count(array_filter($replies, fn (array $reply): bool => $reply['ok']));
}

/**
 * @param  list<string>  $allowed  Rechazos de negocio legítimos en este escenario.
 * @return list<string>
 */
function unexpected(array $replies, array $allowed): array
{
    $codes = [];

    foreach ($replies as $reply) {
        if (! $reply['ok'] && ! in_array($reply['code'], $allowed, true)) {
            $codes[] = $reply['code'];
        }
    }

    return array_map(
        fn (string $code, int $times): string => "{$times}× {$code}",
        array_keys(array_count_values($codes)),
        array_count_values($codes),
    );
}

function execute(array $command): array
{
    Auth::setUser(User::query()->findOrFail($command['user']));
    $scope = AgencyScope::forAgencies([$command['agency']]);

    try {
        match ($command['op']) {
            'pay' => app(RegisterPaymentUseCase::class)->execute([
                'order_id' => $command['order'],
                'amount' => $command['amount'],
                'payment_method' => Payment::METHOD_CASH,
                'idempotency_key' => $command['key'],
            ], $scope),
            'open' => app(OpenCashSessionUseCase::class)->execute(['opening_amount' => '100.00'], $scope),
            'close' => app(CloseCashSessionUseCase::class)->execute($command['session'], [
                'closing_amount' => $command['closing'],
            ], $scope),
            'approve_closure' => app(ApproveCashClosureUseCase::class)->execute($command['closure'], $scope),
            'credit' => app(IssueCreditNoteUseCase::class)->execute([
                'payment_id' => $command['payment'],
                'amount' => $command['amount'],
                'reason' => '01',
            ], $scope),
            'approve_void' => app(ApproveVoidUseCase::class)->execute($command['void'], $scope),
        };

        return ['ok' => true];
    } catch (BusinessRuleException $exception) {
        return ['ok' => false, 'code' => $exception->errorCode];
    } catch (Throwable $exception) {
        return ['ok' => false, 'code' => class_basename($exception).': '.substr($exception->getMessage(), 0, 120)];
    }
}
