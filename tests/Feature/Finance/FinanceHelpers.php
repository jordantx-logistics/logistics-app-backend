<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Src\Customer\Models\Customer;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Cash\UseCases\OpenCashSessionUseCase;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Payment\UseCases\RegisterPaymentUseCase;
use Src\Logistics\Order\Models\Order;
use Src\Organization\Models\Agency;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

function financeAgency(string $code = 'TES01'): Agency
{
    return Agency::factory()->create([
        'code' => $code,
        'name' => 'Agencia '.$code,
        'type' => 'branch',
        'ubigeo' => '150101',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);
}

function financeUser(Agency $agency): User
{
    $user = adminUser();
    $user->agencies()->attach($agency->id, ['is_primary' => true]);

    return $user;
}

function financeCashier(Agency $agency): User
{
    (new PermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('cashier');
    $user->agencies()->attach($agency->id, ['is_primary' => true]);

    return $user;
}

function financeSeries(Agency $agency, string $documentType = 'boleta', string $series = 'B001'): InvoiceSeries
{
    return InvoiceSeries::factory()->create([
        'agency_id' => $agency->id,
        'document_type' => $documentType,
        'series' => $series,
        'issuer_ruc' => '20123456789',
        'issuer_name' => 'Logistics Test SAC',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function financeOrder(string $total = '100.00', array $attributes = []): Order
{
    $sender = Customer::factory()->create();
    $receiver = Customer::factory()->create();

    return Order::factory()->create(array_merge([
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'payment_responsibility' => Order::PAYMENT_SENDER,
        'total_amount' => $total,
        'status' => Order::STATUS_CONFIRMED,
    ], $attributes));
}

function financeScope(Agency $agency): AgencyScope
{
    return AgencyScope::forAgencies([$agency->id]);
}

function financeSession(Agency $agency, string $opening = '50.00'): CashSession
{
    return app(OpenCashSessionUseCase::class)->execute(
        ['opening_amount' => $opening],
        financeScope($agency),
    );
}

function financePayment(
    Agency $agency,
    Order $order,
    string $amount = '25.90',
    string $method = Payment::METHOD_CASH,
    array $overrides = [],
): Payment {
    return app(RegisterPaymentUseCase::class)->execute(array_merge([
        'order_id' => $order->id,
        'amount' => $amount,
        'payment_method' => $method,
        'idempotency_key' => fake()->uuid(),
    ], $overrides), financeScope($agency));
}

function financeError(callable $callback): BusinessRuleException
{
    try {
        $callback();
    } catch (BusinessRuleException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a BusinessRuleException.');
}
