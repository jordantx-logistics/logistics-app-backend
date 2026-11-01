<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Organization\Models\Agency;

function fleetAgency(string $code = 'FLT01'): Agency
{
    return Agency::factory()->create([
        'code' => $code,
        'name' => 'Agencia '.$code,
        'type' => 'branch',
        'timezone' => 'America/Lima',
        'is_active' => true,
    ]);
}

function fleetUserWithRole(string $role, ?Agency $agency = null): User
{
    (new PermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole($role);

    if ($agency !== null) {
        $user->agencies()->attach($agency->id, ['is_primary' => true]);
    }

    return $user;
}

function fleetDriver(Agency $agency, array $attributes = []): Driver
{
    return Driver::factory()->create(array_merge(['home_agency_id' => $agency->id], $attributes));
}

function fleetVehicle(Agency $agency, array $attributes = []): Vehicle
{
    return Vehicle::factory()->create(array_merge(['owner_agency_id' => $agency->id], $attributes));
}

/**
 * Ruta planificada de un tramo, creada por el API.
 */
function fleetPlannedRoute(Agency $origin, Agency $destination): FleetRoute
{
    $response = test()->postJson('/api/v1/fleet/routes', [
        'origin_agency_id' => $origin->id,
        'destination_agency_id' => $destination->id,
        'planned_departure_at' => now()->addDay()->toIso8601String(),
        'segments' => [
            ['from_agency_id' => $origin->id, 'to_agency_id' => $destination->id, 'distance_km' => 320],
        ],
    ])->assertCreated();

    return FleetRoute::query()->findOrFail($response->json('data.id'));
}

function fleetAssignedRoute(Agency $origin, Agency $destination, Driver $driver, Vehicle $vehicle): FleetRoute
{
    $route = fleetPlannedRoute($origin, $destination);

    test()->postJson("/api/v1/fleet/routes/{$route->id}/assign", [
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
    ])->assertOk();

    return $route->refresh();
}

function fleetStartedRoute(Agency $origin, Agency $destination, Driver $driver, Vehicle $vehicle): FleetRoute
{
    $route = fleetAssignedRoute($origin, $destination, $driver, $vehicle);

    test()->postJson("/api/v1/fleet/routes/{$route->id}/start", [
        'odometer_km' => (string) $vehicle->odometer_km,
    ])->assertOk();

    return $route->refresh();
}
