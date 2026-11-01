<?php

use App\Models\User;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Identity\Employee\Models\Employee;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\Shipment;
use Src\Organization\Models\Agency;

/*
|--------------------------------------------------------------------------
| Lo que las pantallas de las apps móviles muestran al personal
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->agency = Agency::factory()->create(['name' => 'Lima Centro']);
    $this->destination = Agency::factory()->create(['name' => 'Cusco']);
    $this->driverUser = User::factory()->create(['name' => 'Luis Quispe']);
    $this->driver = Driver::factory()->create(['user_id' => $this->driverUser->id, 'home_agency_id' => $this->agency->id]);
    $this->actingAs(adminUser());
});

function manifest(Agency $agency, Driver $driver, string $plate, ?string $routeRef): Embark
{
    $vehicle = Vehicle::factory()->create(['plate' => $plate, 'owner_agency_id' => $agency->id]);

    return Embark::query()->create([
        'agency_id' => $agency->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'route_ref' => $routeRef,
        'departed_at' => now(),
    ]);
}

it('muestra la placa y el chofer de cada manifiesto', function () {
    manifest($this->agency, $this->driver, 'B7K-482', 'LIM-CUZ-02');

    $this->getJson('/api/v1/operations/embarks')
        ->assertOk()
        ->assertJsonPath('data.0.vehicle_plate', 'B7K-482')
        ->assertJsonPath('data.0.driver_name', 'Luis Quispe');
});

it('busca manifiestos por parte de la placa o de la ruta', function () {
    manifest($this->agency, $this->driver, 'B7K-482', 'LIM-CUZ-02');
    manifest($this->agency, $this->driver, 'C3M-905', 'LIM-TRU-03');
    manifest($this->agency, $this->driver, 'D9T-310', null);

    $this->getJson('/api/v1/operations/embarks?search=B7K')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vehicle_plate', 'B7K-482');

    $this->getJson('/api/v1/operations/embarks?search=TRU')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.route_ref', 'LIM-TRU-03');

    $this->getJson('/api/v1/operations/embarks?search=ZZZ')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('no trata los comodines de la búsqueda como comodines', function () {
    manifest($this->agency, $this->driver, 'B7K-482', 'LIM-CUZ-02');

    $this->getJson('/api/v1/operations/embarks?search=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('da el detalle de la ruta con agencias y placa', function () {
    $vehicle = Vehicle::factory()->create(['plate' => 'B7K-482', 'owner_agency_id' => $this->agency->id]);
    $route = FleetRoute::factory()->create([
        'origin_agency_id' => $this->agency->id,
        'destination_agency_id' => $this->destination->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $vehicle->id,
    ]);

    RouteSegment::query()->create([
        'route_id' => $route->id,
        'sequence' => 1,
        'from_agency_id' => $this->agency->id,
        'to_agency_id' => $this->destination->id,
        'distance_km' => '100.00',
        'status' => RouteSegment::STATUS_PENDING,
    ]);

    $this->getJson("/api/v1/fleet/routes/{$route->id}")
        ->assertOk()
        ->assertJsonPath('data.origin_agency_name', 'Lima Centro')
        ->assertJsonPath('data.destination_agency_name', 'Cusco')
        ->assertJsonPath('data.vehicle_plate', 'B7K-482')
        ->assertJsonPath('data.segments.0.from_agency_name', 'Lima Centro')
        ->assertJsonPath('data.segments.0.to_agency_name', 'Cusco');
});

it('incluye las entregas cerradas hoy solo si se piden', function () {
    $employee = Employee::factory()->create(['agency_id' => $this->agency->id]);
    $courier = adminUser();
    $courier->forceFill(['employee_id' => $employee->id])->save();

    foreach ([Delivery::STATUS_ASSIGNED, Delivery::STATUS_DELIVERED] as $status) {
        $shipment = Shipment::factory()->create(['origin_agency_id' => $this->agency->id]);

        Delivery::query()->create([
            'shipment_id' => $shipment->id,
            'ticket_code' => $shipment->ticket_code,
            'ticket_number' => $shipment->ticket_number,
            'agency_id' => $this->agency->id,
            'courier_id' => $employee->id,
            'status' => $status,
            'attempt' => 1,
        ]);
    }

    $this->actingAs($courier);

    $this->getJson('/api/v1/operations/deliveries/route-sheet')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/operations/deliveries/route-sheet?closed_today=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
