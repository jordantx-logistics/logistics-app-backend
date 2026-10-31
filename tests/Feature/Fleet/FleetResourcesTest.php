<?php

require_once __DIR__.'/FleetHelpers.php';

use App\Models\User;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Models\AuditLog;

/*
|--------------------------------------------------------------------------
| Flota (Fase 6): choferes y vehículos
|--------------------------------------------------------------------------
*/

it('registra un chofer vinculado a un usuario y a una agencia activa', function () {
    $this->actingAs(adminUser());
    $agency = fleetAgency();
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/fleet/drivers', [
        'user_id' => $user->id,
        'license_number' => 'Q12345678',
        'license_category' => 'A-III',
        'license_expires_at' => now()->addYear()->toDateString(),
        'home_agency_id' => $agency->id,
    ])->assertCreated();

    expect($response->json('data.status'))->toBe(Driver::STATUS_ACTIVE)
        ->and($response->json('data.user_id'))->toBe($user->id);

    $this->postJson('/api/v1/fleet/drivers', [
        'user_id' => $user->id,
        'license_number' => 'Q99999999',
        'license_category' => 'A-III',
        'license_expires_at' => now()->addYear()->toDateString(),
        'home_agency_id' => $agency->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
});

it('rechaza un chofer con usuario inexistente, agencia inactiva o fuera de ámbito', function () {
    $agency = fleetAgency();
    $this->actingAs(adminUser());

    $payload = [
        'user_id' => 999999,
        'license_number' => 'Q12345678',
        'license_category' => 'A-III',
        'license_expires_at' => now()->addYear()->toDateString(),
        'home_agency_id' => $agency->id,
    ];

    $this->postJson('/api/v1/fleet/drivers', $payload)->assertUnprocessable();
    expect($this->postJson('/api/v1/fleet/drivers', $payload)->json('data.error_code'))->toBe('DRIVER_USER_NOT_FOUND');

    $inactive = fleetAgency('FLT02');
    $inactive->update(['is_active' => false]);

    expect($this->postJson('/api/v1/fleet/drivers', [...$payload, 'home_agency_id' => $inactive->id])->json('data.error_code'))
        ->toBe('FLEET_AGENCY_INVALID');

    $manager = fleetUserWithRole('employee', $agency);
    $manager->givePermissionTo('fleet.manage');
    $this->actingAs($manager);

    $this->postJson('/api/v1/fleet/drivers', [
        ...$payload,
        'home_agency_id' => fleetAgency('FLT03')->id,
    ])->assertForbidden()->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');
});

it('lista choferes por ámbito, filtros y disponibilidad', function () {
    $agency = fleetAgency();
    $other = fleetAgency('FLT02');

    $valid = fleetDriver($agency);
    fleetDriver($agency, ['license_expires_at' => now()->subDay()->toDateString()]);
    fleetDriver($agency, ['status' => Driver::STATUS_SUSPENDED]);
    fleetDriver($other);

    $supervisor = fleetUserWithRole('agency-supervisor', $agency);
    $this->actingAs($supervisor);

    expect($this->getJson('/api/v1/fleet/drivers')->assertOk()->json('meta.total'))->toBe(3);

    $available = $this->getJson('/api/v1/fleet/drivers?available=true')->assertOk();
    expect($available->json('data.0.id'))->toBe($valid->id)->and($available->json('meta.total'))->toBe(1);

    expect($this->getJson('/api/v1/fleet/drivers?status=suspended&agency='.$agency->id)->json('meta.total'))->toBe(1);
});

it('actualiza un chofer dentro del ámbito', function () {
    $this->actingAs(adminUser());
    $driver = fleetDriver(fleetAgency());

    $this->patchJson("/api/v1/fleet/drivers/{$driver->id}", ['status' => Driver::STATUS_SUSPENDED])
        ->assertOk()
        ->assertJsonPath('data.status', Driver::STATUS_SUSPENDED);

    $this->patchJson('/api/v1/fleet/drivers/999999', ['status' => Driver::STATUS_ACTIVE])
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'DRIVER_NOT_FOUND');
});

it('registra vehículos con la placa normalizada y sin duplicados', function () {
    $this->actingAs(adminUser());
    $agency = fleetAgency();

    $payload = [
        'plate' => 'abc-123',
        'type' => Vehicle::TYPE_TRUCK,
        'capacity_kg' => 12000,
        'capacity_m3' => 45,
        'owner_agency_id' => $agency->id,
        'odometer_km' => 1000,
    ];

    $this->postJson('/api/v1/fleet/vehicles', $payload)
        ->assertCreated()
        ->assertJsonPath('data.plate', 'ABC123')
        ->assertJsonPath('data.status', Vehicle::STATUS_AVAILABLE);

    $this->postJson('/api/v1/fleet/vehicles', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plate');

    $inactive = fleetAgency('FLT02');
    $inactive->update(['is_active' => false]);

    expect($this->postJson('/api/v1/fleet/vehicles', [...$payload, 'plate' => 'XYZ999', 'owner_agency_id' => $inactive->id])->json('data.error_code'))
        ->toBe('FLEET_AGENCY_INVALID');

    $manager = fleetUserWithRole('employee', $agency);
    $manager->givePermissionTo('fleet.manage');
    $this->actingAs($manager);

    $this->postJson('/api/v1/fleet/vehicles', [...$payload, 'plate' => 'XYZ998', 'owner_agency_id' => $inactive->id])
        ->assertForbidden()
        ->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');
});

it('lista vehículos por estado y agencia', function () {
    $this->actingAs(adminUser());
    $agency = fleetAgency();

    fleetVehicle($agency);
    fleetVehicle($agency, ['status' => Vehicle::STATUS_MAINTENANCE]);

    expect($this->getJson('/api/v1/fleet/vehicles?status=maintenance&agency='.$agency->id)->assertOk()->json('meta.total'))->toBe(1);
});

it('cambia el estado del vehículo salvo que esté en ruta', function () {
    $this->actingAs(adminUser());
    $agency = fleetAgency();
    $vehicle = fleetVehicle($agency);

    $this->patchJson("/api/v1/fleet/vehicles/{$vehicle->id}/status", ['status' => Vehicle::STATUS_MAINTENANCE])
        ->assertOk()
        ->assertJsonPath('data.status', Vehicle::STATUS_MAINTENANCE);

    $this->patchJson("/api/v1/fleet/vehicles/{$vehicle->id}/status", ['status' => Vehicle::STATUS_ON_ROUTE])
        ->assertUnprocessable();

    $vehicle->update(['status' => Vehicle::STATUS_ON_ROUTE]);

    $this->patchJson("/api/v1/fleet/vehicles/{$vehicle->id}/status", ['status' => Vehicle::STATUS_AVAILABLE])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'VEHICLE_ON_ROUTE');

    $this->patchJson('/api/v1/fleet/vehicles/999999/status', ['status' => Vehicle::STATUS_AVAILABLE])
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'VEHICLE_NOT_FOUND');
});

it('audita la corrección del odómetro, la única vía para que retroceda', function () {
    $admin = adminUser();
    $this->actingAs($admin);
    $vehicle = fleetVehicle(fleetAgency(), ['odometer_km' => '200000.00']);

    $this->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/odometer-correction", [
        'odometer_km' => 150000,
        'reason' => 'Mistyped reading',
    ])->assertOk()->assertJsonPath('data.odometer_km', '150000.00');

    $audit = AuditLog::query()->where('action', 'vehicle.odometer_corrected')->sole();

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->before)->toBe(['odometer_km' => '200000.00'])
        ->and($audit->after)->toBe(['odometer_km' => '150000.00', 'reason' => 'Mistyped reading']);

    $this->postJson('/api/v1/fleet/vehicles/999999/odometer-correction', ['odometer_km' => 1, 'reason' => 'x'])
        ->assertNotFound();
});

it('exige los permisos de Flota', function () {
    $this->actingAs(fleetUserWithRole('employee'));

    $this->getJson('/api/v1/fleet/vehicles')->assertForbidden();
    $this->getJson('/api/v1/fleet/drivers')->assertForbidden();
    $this->getJson('/api/v1/fleet/routes')->assertForbidden();
    $this->getJson('/api/v1/fleet/expenses')->assertForbidden();
    $this->postJson('/api/v1/fleet/expenses/liquidate', [])->assertForbidden();
});

it('responde 404 ante un id no numérico', function (string $uri) {
    $this->actingAs(adminUser());

    $this->getJson($uri)->assertNotFound();
})->with([
    '/api/v1/fleet/routes/abc',
    '/api/v1/fleet/routes/abc/summary',
    '/api/v1/fleet/expenses/abc/receipt',
]);
