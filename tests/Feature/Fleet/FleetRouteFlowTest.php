<?php

require_once __DIR__.'/FleetHelpers.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Flota (Fase 6): planificación y ejecución de rutas
|--------------------------------------------------------------------------
|
| Criterios 1 (asignación válida), 2 (eventos) y 3 (kilometraje).
|
*/

function fleetOutboxCount(string $type): int
{
    return OutboxEvent::query()->where('event_type', $type)->count();
}

it('planifica una ruta con tramos encadenados y número RUT', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $middle = fleetAgency('FLT02');
    $destination = fleetAgency('FLT03');

    $response = $this->postJson('/api/v1/fleet/routes', [
        'origin_agency_id' => $origin->id,
        'destination_agency_id' => $destination->id,
        'planned_departure_at' => now()->addDay()->toIso8601String(),
        'segments' => [
            ['from_agency_id' => $origin->id, 'to_agency_id' => $middle->id, 'distance_km' => 320],
            ['from_agency_id' => $middle->id, 'to_agency_id' => $destination->id, 'distance_km' => 410],
        ],
    ])->assertCreated();

    expect($response->json('data.status'))->toBe(FleetRoute::STATUS_PLANNED)
        ->and($response->json('data.ticket_number'))->toStartWith('RUT-')
        ->and($response->json('data.segments'))->toHaveCount(2)
        ->and($response->json('data.segments.1.sequence'))->toBe(2);
});

it('rechaza tramos que no encadenan del origen al destino', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency()->id;
    $destination = fleetAgency('FLT02')->id;
    $other = fleetAgency('FLT03')->id;

    $cases = [
        'no parte del origen' => [['from_agency_id' => $destination, 'to_agency_id' => $origin, 'distance_km' => 1]],
        'no llega al destino' => [['from_agency_id' => $origin, 'to_agency_id' => $other, 'distance_km' => 1]],
        'tramo sobre sí mismo' => [
            ['from_agency_id' => $origin, 'to_agency_id' => $origin, 'distance_km' => 1],
            ['from_agency_id' => $origin, 'to_agency_id' => $destination, 'distance_km' => 1],
        ],
        'con un hueco' => [
            ['from_agency_id' => $origin, 'to_agency_id' => $other, 'distance_km' => 1],
            ['from_agency_id' => $destination, 'to_agency_id' => $destination, 'distance_km' => 1],
        ],
    ];

    foreach ($cases as $segments) {
        $this->postJson('/api/v1/fleet/routes', [
            'origin_agency_id' => $origin,
            'destination_agency_id' => $destination,
            'planned_departure_at' => now()->addDay()->toIso8601String(),
            'segments' => $segments,
        ])->assertUnprocessable()->assertJsonPath('data.error_code', 'ROUTE_SEGMENTS_INVALID');
    }
});

it('rechaza una ruta con agencia inactiva o fuera de ámbito', function () {
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $this->actingAs(adminUser());

    $destination->update(['is_active' => false]);

    $payload = [
        'origin_agency_id' => $origin->id,
        'destination_agency_id' => $destination->id,
        'planned_departure_at' => now()->addDay()->toIso8601String(),
        'segments' => [['from_agency_id' => $origin->id, 'to_agency_id' => $destination->id, 'distance_km' => 1]],
    ];

    $this->postJson('/api/v1/fleet/routes', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'FLEET_AGENCY_INVALID');

    $this->actingAs(fleetUserWithRole('agency-supervisor', $destination));

    $this->postJson('/api/v1/fleet/routes', $payload)
        ->assertForbidden()
        ->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');
});

it('solo asigna chofer activo con licencia vigente y vehículo disponible', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $route = fleetPlannedRoute($origin, fleetAgency('FLT02'));
    $driver = fleetDriver($origin);
    $vehicle = fleetVehicle($origin);
    $uri = "/api/v1/fleet/routes/{$route->id}/assign";

    $this->postJson($uri, ['driver_id' => 999999, 'vehicle_id' => $vehicle->id])
        ->assertNotFound()->assertJsonPath('data.error_code', 'DRIVER_NOT_FOUND');

    $suspended = fleetDriver($origin, ['status' => Driver::STATUS_SUSPENDED]);
    $this->postJson($uri, ['driver_id' => $suspended->id, 'vehicle_id' => $vehicle->id])
        ->assertJsonPath('data.error_code', 'DRIVER_NOT_ACTIVE');

    // Vence antes de la salida planificada, aunque hoy siga vigente.
    $expiring = fleetDriver($origin, ['license_expires_at' => now()->toDateString()]);
    $this->postJson($uri, ['driver_id' => $expiring->id, 'vehicle_id' => $vehicle->id])
        ->assertJsonPath('data.error_code', 'DRIVER_LICENSE_EXPIRED');

    $this->postJson($uri, ['driver_id' => $driver->id, 'vehicle_id' => 999999])
        ->assertNotFound()->assertJsonPath('data.error_code', 'VEHICLE_NOT_FOUND');

    $maintenance = fleetVehicle($origin, ['status' => Vehicle::STATUS_MAINTENANCE]);
    $this->postJson($uri, ['driver_id' => $driver->id, 'vehicle_id' => $maintenance->id])
        ->assertJsonPath('data.error_code', 'VEHICLE_NOT_AVAILABLE');

    $this->postJson($uri, ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_ASSIGNED)
        ->assertJsonPath('data.driver_id', $driver->id);

    $this->postJson('/api/v1/fleet/routes/999999/assign', ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');
});

it('no asigna fuera del ámbito ni una ruta que ya salió', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $route = fleetStartedRoute($origin, $destination, fleetDriver($origin), fleetVehicle($origin));

    $this->postJson("/api/v1/fleet/routes/{$route->id}/assign", [
        'driver_id' => fleetDriver($origin)->id,
        'vehicle_id' => fleetVehicle($origin)->id,
    ])->assertJsonPath('data.error_code', 'ROUTE_INVALID_TRANSITION');

    $planned = fleetPlannedRoute($origin, $destination);
    $outsideDriver = fleetDriver($destination);
    $insideVehicle = fleetVehicle($origin);

    $this->actingAs(fleetUserWithRole('agency-supervisor', $destination));

    $this->postJson("/api/v1/fleet/routes/{$planned->id}/assign", [
        'driver_id' => $outsideDriver->id,
        'vehicle_id' => $insideVehicle->id,
    ])->assertForbidden()->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');

    $this->actingAs(fleetUserWithRole('agency-supervisor', $origin));

    $this->postJson("/api/v1/fleet/routes/{$planned->id}/assign", [
        'driver_id' => $outsideDriver->id,
        'vehicle_id' => $insideVehicle->id,
    ])->assertNotFound()->assertJsonPath('data.error_code', 'DRIVER_NOT_FOUND');
});

it('inicia una ruta: vehículo en ruta, lectura inicial y un solo route.started', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $vehicle = fleetVehicle($origin, ['odometer_km' => '100000.00']);
    $driver = fleetDriver($origin);
    $route = fleetAssignedRoute($origin, fleetAgency('FLT02'), $driver, $vehicle);

    $response = $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => 100010])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_IN_PROGRESS);

    expect($response->json('data.started_at'))->not->toBeNull()
        ->and($vehicle->refresh()->status)->toBe(Vehicle::STATUS_ON_ROUTE)
        ->and($vehicle->odometer_km)->toBe('100010.00')
        ->and(MileageLog::query()->where('route_id', $route->id)->count())->toBe(1)
        ->and(fleetOutboxCount('route.started'))->toBe(1);

    $event = OutboxEvent::query()->where('event_type', 'route.started')->sole();

    expect($event->payload['ticket_number'])->toBe($route->ticket_number)
        ->and($event->payload['driver_id'])->toBe($driver->id)
        ->and($event->payload['vehicle_id'])->toBe($vehicle->id);

    // Idempotente: repetirlo no duplica lectura ni evento.
    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => 100010])->assertOk();

    expect(MileageLog::query()->where('route_id', $route->id)->count())->toBe(1)
        ->and(fleetOutboxCount('route.started'))->toBe(1);
});

it('no inicia una ruta sin recursos, en otro estado o con recursos no válidos', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');

    $this->postJson('/api/v1/fleet/routes/999999/start', ['odometer_km' => 1])
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $planned = fleetPlannedRoute($origin, $destination);
    $this->postJson("/api/v1/fleet/routes/{$planned->id}/start", ['odometer_km' => 1])
        ->assertJsonPath('data.error_code', 'ROUTE_REQUIRES_RESOURCES');

    $driver = fleetDriver($origin);
    $vehicle = fleetVehicle($origin, ['odometer_km' => '500.00']);
    $route = fleetAssignedRoute($origin, $destination, $driver, $vehicle);
    $uri = "/api/v1/fleet/routes/{$route->id}/start";

    $driver->update(['status' => Driver::STATUS_SUSPENDED]);
    $this->postJson($uri, ['odometer_km' => 600])->assertJsonPath('data.error_code', 'DRIVER_NOT_ACTIVE');

    $driver->update(['status' => Driver::STATUS_ACTIVE, 'license_expires_at' => now()->subDay()->toDateString()]);
    $this->postJson($uri, ['odometer_km' => 600])->assertJsonPath('data.error_code', 'DRIVER_LICENSE_EXPIRED');

    $driver->update(['license_expires_at' => now()->addYear()->toDateString()]);
    $vehicle->update(['status' => Vehicle::STATUS_MAINTENANCE]);
    $this->postJson($uri, ['odometer_km' => 600])->assertJsonPath('data.error_code', 'VEHICLE_NOT_AVAILABLE');

    $vehicle->update(['status' => Vehicle::STATUS_AVAILABLE]);
    $this->postJson($uri, ['odometer_km' => 400])->assertJsonPath('data.error_code', 'MILEAGE_NOT_MONOTONIC');

    expect($vehicle->refresh()->odometer_km)->toBe('500.00')
        ->and($route->refresh()->status)->toBe(FleetRoute::STATUS_ASSIGNED);

    $route->update(['status' => FleetRoute::STATUS_CANCELLED]);
    $this->postJson($uri, ['odometer_km' => 600])->assertJsonPath('data.error_code', 'ROUTE_INVALID_TRANSITION');
});

it('no permite que un chofer o vehículo estén en dos rutas en curso', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $driver = fleetDriver($origin);
    $vehicle = fleetVehicle($origin);

    $sameVehicle = fleetAssignedRoute($origin, $destination, fleetDriver($origin), $vehicle);
    $sameDriver = fleetAssignedRoute($origin, $destination, $driver, fleetVehicle($origin));

    fleetStartedRoute($origin, $destination, $driver, $vehicle);

    $this->postJson("/api/v1/fleet/routes/{$sameVehicle->id}/start", ['odometer_km' => 999999])
        ->assertJsonPath('data.error_code', 'VEHICLE_NOT_AVAILABLE');

    $this->postJson("/api/v1/fleet/routes/{$sameDriver->id}/start", ['odometer_km' => 999999])
        ->assertJsonPath('data.error_code', 'DRIVER_ON_ANOTHER_ROUTE');
});

it('completa tramos en orden y sin duplicar el evento', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $middle = fleetAgency('FLT02');
    $destination = fleetAgency('FLT03');

    $id = $this->postJson('/api/v1/fleet/routes', [
        'origin_agency_id' => $origin->id,
        'destination_agency_id' => $destination->id,
        'planned_departure_at' => now()->addDay()->toIso8601String(),
        'segments' => [
            ['from_agency_id' => $origin->id, 'to_agency_id' => $middle->id, 'distance_km' => 320],
            ['from_agency_id' => $middle->id, 'to_agency_id' => $destination->id, 'distance_km' => 410],
        ],
    ])->json('data.id');

    [$first, $second] = RouteSegment::query()->where('route_id', $id)->orderBy('sequence')->get()->all();

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/{$first->id}/complete")
        ->assertJsonPath('data.error_code', 'ROUTE_NOT_IN_PROGRESS');

    $vehicle = fleetVehicle($origin);
    $this->postJson("/api/v1/fleet/routes/{$id}/assign", ['driver_id' => fleetDriver($origin)->id, 'vehicle_id' => $vehicle->id])->assertOk();
    $this->postJson("/api/v1/fleet/routes/{$id}/start", ['odometer_km' => (string) $vehicle->odometer_km])->assertOk();

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/{$second->id}/complete")
        ->assertJsonPath('data.error_code', 'ROUTE_SEGMENT_OUT_OF_SEQUENCE');

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/999999/complete")
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_SEGMENT_NOT_FOUND');

    $this->postJson('/api/v1/fleet/routes/999999/segments/1/complete')
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/{$first->id}/complete")
        ->assertOk()->assertJsonPath('data.status', RouteSegment::STATUS_COMPLETED);

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/{$first->id}/complete")->assertOk();

    expect(fleetOutboxCount('route.segment-completed'))->toBe(1);

    $this->postJson("/api/v1/fleet/routes/{$id}/segments/{$second->id}/complete")->assertOk();
});

it('calcula el kilometraje de la ruta como lectura final menos inicial', function (string $start, string $end, string $expected) {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $vehicle = fleetVehicle($origin, ['odometer_km' => '0.00']);
    $route = fleetAssignedRoute($origin, fleetAgency('FLT02'), fleetDriver($origin), $vehicle);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => $start])->assertOk();
    $this->postJson("/api/v1/fleet/routes/{$route->id}/mileage", ['odometer_km' => $end])->assertCreated();

    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => $end])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_FINISHED)
        ->assertJsonPath('data.computed_km', $expected);

    expect($vehicle->refresh()->status)->toBe(Vehicle::STATUS_AVAILABLE)
        ->and(fleetOutboxCount('route.finished'))->toBe(1)
        ->and(OutboxEvent::query()->where('event_type', 'route.finished')->sole()->payload['computed_km'])->toBe($expected);

    // Idempotente: repetirlo no vuelve a escribir.
    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => $end])->assertOk();

    expect(fleetOutboxCount('route.finished'))->toBe(1);
})->with([
    'tramo normal' => ['153200.00', '153940.00', '740.00'],
    'decimales' => ['153200.10', '153200.35', '0.25'],
    'sin avance' => ['1000.00', '1000.00', '0.00'],
    'coma flotante' => ['0.10', '0.30', '0.20'],
]);

it('no finaliza una ruta que no está en curso ni con el odómetro retrocediendo', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');

    $this->postJson('/api/v1/fleet/routes/999999/finish', ['odometer_km' => 1])
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $planned = fleetAssignedRoute($origin, $destination, fleetDriver($origin), fleetVehicle($origin));
    $this->postJson("/api/v1/fleet/routes/{$planned->id}/finish", ['odometer_km' => 999999])
        ->assertJsonPath('data.error_code', 'ROUTE_NOT_IN_PROGRESS');

    $vehicle = fleetVehicle($origin, ['odometer_km' => '500.00']);
    $route = fleetStartedRoute($origin, $destination, fleetDriver($origin), $vehicle);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => 499])
        ->assertJsonPath('data.error_code', 'MILEAGE_NOT_MONOTONIC');

    expect($route->refresh()->status)->toBe(FleetRoute::STATUS_IN_PROGRESS);
});

it('registra lecturas de odómetro monótonas, idempotentes y con foto', function () {
    Storage::fake('local');

    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $vehicle = fleetVehicle($origin, ['odometer_km' => '1000.00']);
    $route = fleetStartedRoute($origin, fleetAgency('FLT02'), fleetDriver($origin), $vehicle);
    $uri = "/api/v1/fleet/routes/{$route->id}/mileage";

    $created = $this->post($uri, [
        'odometer_km' => 1100,
        'idempotency_key' => 'reading-1',
        'photo' => UploadedFile::fake()->image('odometer.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($created->json('data.has_photo'))->toBeTrue();

    $log = MileageLog::query()->findOrFail($created->json('data.id'));
    Storage::disk('local')->assertExists($log->photo_path);

    // Reintento offline: misma lectura, sin duplicar.
    $this->postJson($uri, ['odometer_km' => 1100, 'idempotency_key' => 'reading-1'])->assertCreated();
    expect(MileageLog::query()->where('idempotency_key', 'reading-1')->count())->toBe(1);

    $this->postJson($uri, ['odometer_km' => 1050])->assertJsonPath('data.error_code', 'MILEAGE_NOT_MONOTONIC');
    expect($vehicle->refresh()->odometer_km)->toBe('1100.00');

    $this->get("/api/v1/fleet/routes/{$route->id}/mileage/{$log->id}/photo")->assertOk();

    $withoutPhoto = MileageLog::query()->where('route_id', $route->id)->whereNull('photo_path')->firstOrFail();
    $this->getJson("/api/v1/fleet/routes/{$route->id}/mileage/{$withoutPhoto->id}/photo")
        ->assertNotFound()->assertJsonPath('data.error_code', 'FLEET_EVIDENCE_NOT_FOUND');

    $this->postJson('/api/v1/fleet/routes/999999/mileage', ['odometer_km' => 1])
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');
});

it('no deja archivos huérfanos si la lectura se rechaza', function () {
    Storage::fake('local');

    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $vehicle = fleetVehicle($origin, ['odometer_km' => '1000.00']);
    $route = fleetStartedRoute($origin, fleetAgency('FLT02'), fleetDriver($origin), $vehicle);

    $this->post("/api/v1/fleet/routes/{$route->id}/mileage", [
        'odometer_km' => 10,
        'photo' => UploadedFile::fake()->image('odometer.jpg'),
    ], ['Accept' => 'application/json'])->assertJsonPath('data.error_code', 'MILEAGE_NOT_MONOTONIC');

    expect(Storage::disk('local')->allFiles())->toBe([]);

    $route->update(['status' => FleetRoute::STATUS_FINISHED]);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/mileage", ['odometer_km' => 2000])
        ->assertJsonPath('data.error_code', 'ROUTE_NOT_IN_PROGRESS');
});

it('cancela una ruta sin salir y publica route.cancelled', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $route = fleetPlannedRoute($origin, $destination);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/cancel", ['reason' => 'Vehicle breakdown'])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_CANCELLED)
        ->assertJsonPath('data.cancel_reason', 'Vehicle breakdown');

    expect(fleetOutboxCount('route.cancelled'))->toBe(1);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/cancel", ['reason' => 'Twice'])
        ->assertJsonPath('data.error_code', 'ROUTE_INVALID_TRANSITION');

    $this->postJson('/api/v1/fleet/routes/999999/cancel', ['reason' => 'x'])
        ->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $outside = fleetPlannedRoute($origin, $destination);
    $this->actingAs(fleetUserWithRole('agency-supervisor', $destination));

    $this->postJson("/api/v1/fleet/routes/{$outside->id}/cancel", ['reason' => 'x'])
        ->assertForbidden()->assertJsonPath('data.error_code', 'FLEET_AGENCY_SCOPE');
});

it('deja operar la ruta solo a su chofer o a un supervisor de la agencia de origen', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $vehicle = fleetVehicle($origin, ['odometer_km' => '100.00']);
    $driverUser = fleetUserWithRole('driver');
    $driver = fleetDriver($origin, ['user_id' => $driverUser->id]);
    $route = fleetAssignedRoute($origin, $destination, $driver, $vehicle);

    $otherDriver = fleetUserWithRole('driver');
    fleetDriver($origin, ['user_id' => $otherDriver->id]);

    $this->actingAs($otherDriver);
    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => 100])
        ->assertForbidden()->assertJsonPath('data.error_code', 'ROUTE_NOT_ASSIGNED_TO_USER');

    $this->actingAs(fleetUserWithRole('agency-supervisor', $destination));
    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => 100])
        ->assertForbidden()->assertJsonPath('data.error_code', 'ROUTE_NOT_ASSIGNED_TO_USER');

    $this->actingAs($driverUser);
    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => 110])->assertOk();

    $this->actingAs(fleetUserWithRole('agency-supervisor', $origin));
    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => 120])->assertOk();

    $this->actingAs($otherDriver);
    $unassigned = FleetRoute::factory()->create(['origin_agency_id' => $origin->id, 'destination_agency_id' => $destination->id]);
    $this->postJson("/api/v1/fleet/routes/{$unassigned->id}/start", ['odometer_km' => 1])
        ->assertForbidden();
});

it('lista y muestra rutas según el ámbito, y al chofer las suyas', function () {
    $this->actingAs(adminUser());
    $origin = fleetAgency();
    $destination = fleetAgency('FLT02');
    $elsewhere = fleetAgency('FLT03');

    $vehicle = fleetVehicle($origin);
    $driverUser = fleetUserWithRole('driver');
    $driver = fleetDriver($origin, ['user_id' => $driverUser->id]);

    $mine = fleetAssignedRoute($origin, $destination, $driver, $vehicle);
    $other = fleetPlannedRoute($elsewhere, $destination);

    $admin = $this->getJson('/api/v1/fleet/routes?status=assigned,planned&agency='.$destination->id)->assertOk();
    expect($admin->json('meta.total'))->toBe(2);

    expect($this->getJson('/api/v1/fleet/routes?driver_id='.$driver->id)->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/fleet/routes?vehicle_id='.$vehicle->id)->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/fleet/routes?ticket_number='.$other->ticket_number)->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/fleet/routes?departure_from='.now()->addDays(5)->toDateString())->json('meta.total'))->toBe(0)
        ->and($this->getJson('/api/v1/fleet/routes?departure_to='.now()->addDays(5)->toDateString())->json('meta.total'))->toBe(2);

    // El chofer no tiene agencias: ve solo la que conduce.
    $this->actingAs($driverUser);

    $list = $this->getJson('/api/v1/fleet/routes')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.id'))->toBe($mine->id);

    $this->getJson("/api/v1/fleet/routes/{$mine->id}")->assertOk()->assertJsonCount(1, 'data.segments');
    $this->getJson("/api/v1/fleet/routes/{$other->id}")->assertNotFound()->assertJsonPath('data.error_code', 'ROUTE_NOT_FOUND');

    $this->actingAs(fleetUserWithRole('agency-supervisor', $origin));
    expect($this->getJson('/api/v1/fleet/routes')->json('meta.total'))->toBe(1);
});
