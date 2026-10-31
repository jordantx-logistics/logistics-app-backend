<?php

require_once __DIR__.'/FleetHelpers.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\ConsumptionCalculator;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Models\AuditLog;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Flota (Fase 6): viáticos, combustible y consumo
|--------------------------------------------------------------------------
|
| Criterios 4 (consumo), 5 (viáticos) y 6 (combustible).
|
*/

beforeEach(function () {
    Storage::fake('local');

    $this->actingAs(adminUser());
    $this->origin = fleetAgency();
    $this->destination = fleetAgency('FLT02');
    $this->vehicle = fleetVehicle($this->origin, ['odometer_km' => '1000.00']);
    $this->route = fleetStartedRoute($this->origin, $this->destination, fleetDriver($this->origin), $this->vehicle);
});

function fleetPostExpense(array $data): TestResponse
{
    return test()->post('/api/v1/fleet/expenses', $data, ['Accept' => 'application/json']);
}

function fleetFuelPayload(FleetRoute $route, array $overrides = []): array
{
    return array_merge([
        'route_id' => $route->id,
        'type' => Expense::TYPE_FUEL,
        'amount' => '380.00',
        'liters' => '95.00',
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ], $overrides);
}

it('registra combustible con comprobante almacenado y publica expense.registered', function () {
    $response = fleetPostExpense(fleetFuelPayload($this->route, ['odometer_km' => '1200.00']))
        ->assertCreated()
        ->assertJsonPath('data.status', Expense::STATUS_REGISTERED)
        ->assertJsonPath('data.has_receipt', true);

    expect($response->json('data'))->not->toHaveKey('receipt_path');

    $expense = Expense::query()->findOrFail($response->json('data.id'));

    Storage::disk('local')->assertExists($expense->receipt_path);

    expect($this->vehicle->refresh()->odometer_km)->toBe('1200.00')
        ->and(OutboxEvent::query()->where('event_type', 'expense.registered')->count())->toBe(1);

    $this->get("/api/v1/fleet/expenses/{$expense->id}/receipt")->assertOk();
});

it('exige comprobante y litros en el combustible', function () {
    fleetPostExpense(fleetFuelPayload($this->route, ['receipt' => null]))
        ->assertJsonPath('data.error_code', 'EXPENSE_RECEIPT_REQUIRED');

    fleetPostExpense(fleetFuelPayload($this->route, ['liters' => null]))
        ->assertJsonPath('data.error_code', 'EXPENSE_LITERS_REQUIRED');

    expect(Storage::disk('local')->allFiles())->toBe([]);

    fleetPostExpense(fleetFuelPayload($this->route, ['receipt' => UploadedFile::fake()->create('virus.exe', 10)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');
});

it('no deja que el odómetro de una carga retroceda ni guarda el comprobante', function () {
    fleetPostExpense(fleetFuelPayload($this->route, ['odometer_km' => '999.00']))
        ->assertJsonPath('data.error_code', 'MILEAGE_NOT_MONOTONIC');

    expect(Expense::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($this->vehicle->refresh()->odometer_km)->toBe('1000.00');
});

it('aplica el tope de viático por ruta', function () {
    config()->set('fleet.per_diem_max', '500.00');

    $perDiem = fn (string $amount) => fleetPostExpense([
        'route_id' => $this->route->id,
        'type' => Expense::TYPE_PER_DIEM,
        'amount' => $amount,
    ]);

    $perDiem('300.00')->assertCreated()->assertJsonPath('data.has_receipt', false);
    $perDiem('200.00')->assertCreated();
    $perDiem('0.01')->assertJsonPath('data.error_code', 'EXPENSE_PER_DIEM_LIMIT');

    // Un viático rechazado deja de contar.
    Expense::query()->first()->update(['status' => Expense::STATUS_REJECTED]);
    $perDiem('300.00')->assertCreated();
});

it('solo registra gastos en rutas abiertas y de quien la opera', function () {
    fleetPostExpense(fleetFuelPayload($this->route, ['route_id' => 999999]))
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $planned = fleetPlannedRoute($this->origin, $this->destination);
    fleetPostExpense(fleetFuelPayload($planned))->assertJsonPath('data.error_code', 'EXPENSE_ROUTE_CLOSED');

    $this->actingAs(fleetUserWithRole('driver'));
    fleetPostExpense(fleetFuelPayload($this->route))
        ->assertForbidden()->assertJsonPath('data.error_code', 'ROUTE_NOT_ASSIGNED_TO_USER');
});

it('es idempotente por clave y rechaza una clave con otros datos', function () {
    $first = fleetPostExpense(fleetFuelPayload($this->route, ['idempotency_key' => 'fuel-1']))->assertCreated();
    $again = fleetPostExpense(fleetFuelPayload($this->route, ['idempotency_key' => 'fuel-1']))->assertCreated();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Expense::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    fleetPostExpense(fleetFuelPayload($this->route, ['idempotency_key' => 'fuel-1', 'amount' => '1.00']))
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'EXPENSE_IDEMPOTENCY_CONFLICT');
});

it('rinde un viático: adjunta el comprobante y solo entonces se liquida', function () {
    $id = fleetPostExpense([
        'route_id' => $this->route->id,
        'type' => Expense::TYPE_PER_DIEM,
        'amount' => '120.00',
    ])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$id], 'decision' => 'liquidated'])
        ->assertJsonPath('data.error_code', 'EXPENSE_ROUTE_NOT_CLOSED');

    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/finish", ['odometer_km' => 1100])->assertOk();

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$id], 'decision' => 'liquidated'])
        ->assertJsonPath('data.error_code', 'EXPENSE_RECEIPT_REQUIRED');

    $this->getJson("/api/v1/fleet/expenses/{$id}/receipt")
        ->assertNotFound()->assertJsonPath('data.error_code', 'FLEET_EVIDENCE_NOT_FOUND');

    $this->post("/api/v1/fleet/expenses/{$id}/receipt", [
        'receipt' => UploadedFile::fake()->create('settlement.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_receipt', true);

    $firstPath = Expense::query()->findOrFail($id)->receipt_path;

    // Reemplazar el comprobante borra el anterior.
    $this->post("/api/v1/fleet/expenses/{$id}/receipt", [
        'receipt' => UploadedFile::fake()->image('settlement.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('local')->assertMissing($firstPath);

    $liquidated = $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$id], 'decision' => 'liquidated'])
        ->assertOk();

    expect($liquidated->json('data.0.status'))->toBe(Expense::STATUS_LIQUIDATED)
        ->and(AuditLog::query()->where('action', 'expense.liquidated')->where('entity_id', $id)->count())->toBe(1);

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$id], 'decision' => 'rejected', 'reason' => 'x'])
        ->assertJsonPath('data.error_code', 'EXPENSE_ALREADY_REVIEWED');

    $this->post("/api/v1/fleet/expenses/{$id}/receipt", [
        'receipt' => UploadedFile::fake()->image('late.jpg'),
    ], ['Accept' => 'application/json'])->assertJsonPath('data.error_code', 'EXPENSE_ALREADY_REVIEWED');

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('rechaza gastos con motivo y de forma atómica', function () {
    $good = fleetPostExpense(fleetFuelPayload($this->route))->assertCreated()->json('data.id');
    $noReceipt = fleetPostExpense([
        'route_id' => $this->route->id,
        'type' => Expense::TYPE_PER_DIEM,
        'amount' => '10.00',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/finish", ['odometer_km' => 1100])->assertOk();

    // Un gasto inválido deja todo el lote como estaba.
    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$good, $noReceipt], 'decision' => 'liquidated'])
        ->assertJsonPath('data.error_code', 'EXPENSE_RECEIPT_REQUIRED');

    expect(Expense::query()->findOrFail($good)->status)->toBe(Expense::STATUS_REGISTERED);

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$good], 'decision' => 'rejected'])
        ->assertJsonPath('data.error_code', 'EXPENSE_REJECTION_REASON_REQUIRED');

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [999999], 'decision' => 'liquidated'])
        ->assertNotFound()->assertJsonPath('data.error_code', 'EXPENSE_NOT_FOUND');

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$good], 'decision' => 'rejected', 'reason' => 'Illegible'])
        ->assertOk()
        ->assertJsonPath('data.0.status', Expense::STATUS_REJECTED)
        ->assertJsonPath('data.0.rejection_reason', 'Illegible');

    $this->actingAs(fleetUserWithRole('agency-supervisor', $this->destination));

    $this->postJson('/api/v1/fleet/expenses/liquidate', ['expense_ids' => [$noReceipt], 'decision' => 'rejected', 'reason' => 'x'])
        ->assertForbidden()->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');
});

it('valida el adjunto de un comprobante', function () {
    $this->post('/api/v1/fleet/expenses/999999/receipt', [
        'receipt' => UploadedFile::fake()->image('x.jpg'),
    ], ['Accept' => 'application/json'])->assertNotFound()->assertJsonPath('data.error_code', 'EXPENSE_NOT_FOUND');
});

it('lista gastos según lo que el usuario puede ver', function () {
    fleetPostExpense(fleetFuelPayload($this->route))->assertCreated();
    fleetPostExpense(['route_id' => $this->route->id, 'type' => Expense::TYPE_PER_DIEM, 'amount' => '10.00'])->assertCreated();

    expect($this->getJson('/api/v1/fleet/expenses?route_id='.$this->route->id)->assertOk()->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/fleet/expenses?type=fuel')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/fleet/expenses?status=rejected')->json('meta.total'))->toBe(0);

    $this->actingAs(fleetUserWithRole('agency-supervisor', fleetAgency('FLT09')));
    expect($this->getJson('/api/v1/fleet/expenses')->json('meta.total'))->toBe(0);
});

it('resume coste y consumo sin contar los gastos rechazados', function () {
    fleetPostExpense(fleetFuelPayload($this->route, ['amount' => '100.00', 'liters' => '50.00']))->assertCreated();
    fleetPostExpense(['route_id' => $this->route->id, 'type' => Expense::TYPE_PER_DIEM, 'amount' => '50.00'])->assertCreated();

    $rejected = fleetPostExpense(fleetFuelPayload($this->route, ['amount' => '999.00', 'liters' => '99.00']))->assertCreated()->json('data.id');
    Expense::query()->findOrFail($rejected)->update(['status' => Expense::STATUS_REJECTED]);

    // En curso: el kilometraje sale de las lecturas.
    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/mileage", ['odometer_km' => 1100])->assertCreated();
    expect($this->getJson("/api/v1/fleet/routes/{$this->route->id}/summary")->json('data.km'))->toBe('100.00');

    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/finish", ['odometer_km' => 1200])->assertOk();

    $this->getJson("/api/v1/fleet/routes/{$this->route->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.km', '200.00')
        ->assertJsonPath('data.liters', '50.00')
        ->assertJsonPath('data.fuel_cost', '100.00')
        ->assertJsonPath('data.per_diem_cost', '50.00')
        ->assertJsonPath('data.total_cost', '150.00')
        ->assertJsonPath('data.km_per_liter', 4)
        ->assertJsonPath('data.cost_per_km', 0.75)
        ->assertJsonPath('data.is_anomalous', false);
});

it('alerta sin bloquear cuando el consumo está fuera de rango', function () {
    Log::spy();

    // Un camión normal rinde 2-6 km/L: 100 km con 10 L son 10 km/L.
    fleetPostExpense(fleetFuelPayload($this->route, ['liters' => '10.00']))->assertCreated();

    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/finish", ['odometer_km' => 1100])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_FINISHED);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'fleet.consumption_anomaly'
        && $context['route_id'] === $this->route->id
        && $context['km_per_liter'] === 10.0);

    expect($this->getJson("/api/v1/fleet/routes/{$this->route->id}/summary")->json('data.is_anomalous'))->toBeTrue();
});

it('no alerta en un consumo normal ni sin datos de combustible', function () {
    Log::spy();

    $this->postJson("/api/v1/fleet/routes/{$this->route->id}/finish", ['odometer_km' => 1100])->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('usa el rango de consumo del tipo de vehículo', function () {
    $van = fleetVehicle($this->origin, ['type' => Vehicle::TYPE_VAN, 'odometer_km' => '0.00']);
    $route = fleetStartedRoute($this->origin, $this->destination, fleetDriver($this->origin), $van);

    fleetPostExpense(fleetFuelPayload($route, ['liters' => '10.00']))->assertCreated();
    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => 100])->assertOk();

    // 10 km/L es anómalo en un camión, pero normal en una van.
    expect($this->getJson("/api/v1/fleet/routes/{$route->id}/summary")->json('data.is_anomalous'))->toBeFalse();
});

it('calcula el consumo de forma determinista', function (float $km, float $liters, ?float $kmPerLiter) {
    expect((new ConsumptionCalculator)->kmPerLiter($km, $liters))->toBe($kmPerLiter);
})->with([
    'normal' => [740.0, 95.0, 7.79],
    'exacto' => [200.0, 50.0, 4.0],
    'sin distancia' => [0.0, 50.0, null],
    'sin combustible' => [100.0, 0.0, null],
]);

it('calcula el coste por kilómetro y la anomalía de forma determinista', function () {
    $calculator = new ConsumptionCalculator;

    expect($calculator->costPerKm(200.0, 150.0))->toBe(0.75)
        ->and($calculator->costPerKm(0.0, 150.0))->toBeNull()
        ->and($calculator->isAnomalous(1.9, ['min' => 2, 'max' => 6]))->toBeTrue()
        ->and($calculator->isAnomalous(2.0, ['min' => 2, 'max' => 6]))->toBeFalse()
        ->and($calculator->isAnomalous(6.0, ['min' => 2, 'max' => 6]))->toBeFalse()
        ->and($calculator->isAnomalous(6.1, ['min' => 2, 'max' => 6]))->toBeTrue()
        ->and($calculator->isAnomalous(null, ['min' => 2, 'max' => 6]))->toBeFalse()
        ->and($calculator->isAnomalous(9.0, null))->toBeFalse();
});
