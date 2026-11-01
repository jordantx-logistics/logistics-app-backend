<?php

require_once __DIR__.'/FleetHelpers.php';

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Operations\Shipment\Jobs\AlertRouteFinishedWithPendingJob;
use Src\Operations\Shipment\Jobs\MarkRouteShipmentsInTransitJob;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\OutboxEventMap;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Flota (Fase 6): enlace opcional con Operaciones
|--------------------------------------------------------------------------
|
| `embarks.route_ref` con un `RUT-…` enlaza el manifiesto a la ruta. Cualquier
| otra referencia sigue funcionando como en la Fase 4.
|
*/

beforeEach(function () {
    $this->actingAs(adminUser());
    $this->origin = fleetAgency();
    $this->destination = fleetAgency('FLT02');
    $this->driver = fleetDriver($this->origin);
    $this->vehicle = fleetVehicle($this->origin);
});

function fleetReceivedShipment(int $originId, int $destinationId): Shipment
{
    return Shipment::factory()->create([
        'origin_agency_id' => $originId,
        'destination_agency_id' => $destinationId,
        'current_location_agency_id' => $originId,
        'status' => ShipmentStatus::RECEIVED,
        'order_id' => fake()->unique()->numberBetween(1, 1_000_000),
    ]);
}

function fleetEmbark(int $agencyId, int $vehicleId, int $driverId, ?string $routeRef, Shipment $shipment): TestResponse
{
    return test()->postJson('/api/v1/operations/embarks', [
        'agency_id' => $agencyId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'route_ref' => $routeRef,
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ]);
}

/** Ejecuta los consumidores registrados del último evento de ese tipo. */
function fleetConsume(string $eventType): void
{
    $event = OutboxEvent::query()->where('event_type', $eventType)->latest('id')->firstOrFail();

    foreach (app(OutboxEventMap::class)->jobsFor($eventType) as $job) {
        app()->call([new $job($event->id, $event->payload), 'handle']);
    }
}

it('pasa a in_transit los envíos embarcados en la ruta al iniciarla, una sola vez', function () {
    $route = fleetAssignedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);
    $linked = fleetReceivedShipment($this->origin->id, $this->destination->id);
    $unlinked = fleetReceivedShipment($this->origin->id, $this->destination->id);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, $route->ticket_number, $linked)->assertCreated();
    fleetEmbark($this->origin->id, 7, 15, 'LIM-TRU-01', $unlinked)->assertCreated();

    expect($linked->refresh()->status)->toBe(ShipmentStatus::EMBARKED);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => (string) $this->vehicle->odometer_km])->assertOk();
    fleetConsume('route.started');
    fleetConsume('route.started');

    expect($linked->refresh()->status)->toBe(ShipmentStatus::IN_TRANSIT)
        ->and($unlinked->refresh()->status)->toBe(ShipmentStatus::EMBARKED)
        ->and(TrackingEvent::query()->where('shipment_id', $linked->id)->where('event_type', 'in_transit')->count())->toBe(1);
});

it('registra los consumidores de los eventos de Flota', function () {
    $map = app(OutboxEventMap::class);

    expect($map->jobsFor('route.started'))->toContain(MarkRouteShipmentsInTransitJob::class)
        ->and($map->jobsFor('route.finished'))->toContain(AlertRouteFinishedWithPendingJob::class)
        ->and($map->jobsFor('route.segment-completed'))->not->toBeEmpty()
        ->and($map->jobsFor('route.cancelled'))->not->toBeEmpty()
        ->and($map->jobsFor('expense.registered'))->not->toBeEmpty();
});

it('no toca un envío que ya no está embarcado', function () {
    $route = fleetAssignedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);
    $shipment = fleetReceivedShipment($this->origin->id, $this->destination->id);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, $route->ticket_number, $shipment)->assertCreated();
    $shipment->update(['status' => ShipmentStatus::CANCELLED]);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/start", ['odometer_km' => (string) $this->vehicle->odometer_km])->assertOk();
    fleetConsume('route.started');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::CANCELLED);
});

it('embarca directamente en tránsito sobre una ruta en curso', function () {
    $route = fleetStartedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);
    $shipment = fleetReceivedShipment($this->origin->id, $this->destination->id);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, $route->ticket_number, $shipment)->assertCreated();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::IN_TRANSIT);
});

it('valida el enlace del embarque con la ruta', function () {
    $route = fleetAssignedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);
    $shipment = fleetReceivedShipment($this->origin->id, $this->destination->id);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, 'RUT-2026-999999', $shipment)
        ->assertJsonPath('data.error_code', 'EMBARK_ROUTE_NOT_FOUND');

    fleetEmbark($this->origin->id, 999, $this->driver->id, $route->ticket_number, $shipment)
        ->assertJsonPath('data.error_code', 'EMBARK_ROUTE_RESOURCE_MISMATCH');

    fleetEmbark($this->origin->id, $this->vehicle->id, 999, $route->ticket_number, $shipment)
        ->assertJsonPath('data.error_code', 'EMBARK_ROUTE_RESOURCE_MISMATCH');

    $route->update(['status' => FleetRoute::STATUS_FINISHED]);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, $route->ticket_number, $shipment)
        ->assertJsonPath('data.error_code', 'EMBARK_ROUTE_NOT_AVAILABLE');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED);
});

it('alerta sin bloquear si la ruta termina con envíos sin desembarcar', function () {
    Log::spy();

    $route = fleetStartedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);
    $shipment = fleetReceivedShipment($this->origin->id, $this->destination->id);

    fleetEmbark($this->origin->id, $this->vehicle->id, $this->driver->id, $route->ticket_number, $shipment)->assertCreated();

    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => (float) $this->vehicle->odometer_km + 100])
        ->assertOk()
        ->assertJsonPath('data.status', FleetRoute::STATUS_FINISHED);

    fleetConsume('route.finished');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'fleet.route_finished_with_pending_shipments'
        && $context['pending'] === 1);
});

it('no alerta si todo se desembarcó', function () {
    Log::spy();

    $route = fleetStartedRoute($this->origin, $this->destination, $this->driver, $this->vehicle);

    $this->postJson("/api/v1/fleet/routes/{$route->id}/finish", ['odometer_km' => (float) $this->vehicle->odometer_km + 100])->assertOk();
    fleetConsume('route.finished');

    Log::shouldNotHaveReceived('warning');
});
