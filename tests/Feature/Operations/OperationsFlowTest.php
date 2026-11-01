<?php

use Illuminate\Support\Str;
use Src\Identity\Employee\Models\Employee;
use Src\Logistics\Order\Jobs\MarkOrderDeliveredJob;
use Src\Logistics\Order\Jobs\MarkOrderInTransitJob;
use Src\Logistics\Order\Models\Order;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Shipment\Jobs\CancelShipmentJob;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Tracking\Models\Scan;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Operaciones / Tracking (Fase 4)
|--------------------------------------------------------------------------
|
| Recorrido completo: proyección → recepción → embarque → desembarque →
| asignación → entrega, más los consumidores de Core y los escaneos de PDA.
|
*/

function operationsShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create(array_merge([
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::CREATED,
        'order_id' => fake()->unique()->numberBetween(1, 1_000_000),
    ], $attributes));
}

it('recibe, embarca, desembarca y entrega un envío de extremo a extremo', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = operationsShipment();
    $courier = Employee::factory()->create(['agency_id' => 2, 'status' => Employee::STATUS_ACTIVE]);

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 1,
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ])->assertCreated()->assertJsonPath('data.accepted', 1);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED);

    $embark = $this->postJson('/api/v1/operations/embarks', [
        'agency_id' => 1,
        'vehicle_id' => 10,
        'driver_id' => 20,
        'route_ref' => 'LIM-TRU-01',
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ])->assertCreated()->json('data.embark');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::EMBARKED);

    $this->postJson("/api/v1/operations/embarks/{$embark['id']}/disembark", [
        'agency_id' => 2,
    ])->assertOk()->assertJsonPath('data.accepted', 1);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::DISEMBARKED)
        ->and($shipment->current_location_agency_id)->toBe(2);

    $delivery = $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [$shipment->id],
    ])->assertCreated()->json('data.0');

    $this->postJson("/api/v1/operations/deliveries/{$delivery['id']}/complete", [
        'recipient_name' => 'Ana Pérez',
        'recipient_document' => '12345678',
        'signature_path' => 'deliveries/1/signature.png',
        'photo_path' => 'deliveries/1/photo.jpg',
        'latitude' => -12.0464,
        'longitude' => -77.0428,
        'captured_at' => now()->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.delivery.status', Delivery::STATUS_DELIVERED);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::DELIVERED)
        ->and(OutboxEvent::query()->where('event_type', 'delivery.completed')->count())->toBe(1)
        ->and(Scan::query()->count())->toBe(0);
});

it('proyecta order.created y cierra la orden con los eventos de Operaciones', function () {
    $order = Order::factory()->create([
        'status' => Order::STATUS_CONFIRMED,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
    ]);

    $shipment = operationsShipment([
        'order_id' => $order->id,
        'status' => ShipmentStatus::EMBARKED,
    ]);

    $embarked = OutboxEvent::create([
        'event_type' => 'shipment.embarked',
        'payload' => ['order_id' => $order->id],
    ]);

    app()->call([new MarkOrderInTransitJob($embarked->id, $embarked->payload), 'handle']);

    expect($order->refresh()->status)->toBe(Order::STATUS_IN_TRANSIT);

    $delivered = OutboxEvent::create([
        'event_type' => 'delivery.completed',
        'payload' => ['order_id' => $order->id, 'shipment_id' => $shipment->id],
    ]);

    app()->call([new MarkOrderDeliveredJob($delivered->id, $delivered->payload), 'handle']);

    expect($order->refresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and($order->fresh()->delivered_at)->not->toBeNull();
});

it('cancela el envío cuando la orden se cancela antes del reparto', function () {
    $order = Order::factory()->create(['status' => Order::STATUS_CONFIRMED]);
    $shipment = operationsShipment([
        'order_id' => $order->id,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    $event = OutboxEvent::create([
        'event_type' => 'order.cancelled',
        'payload' => ['order_id' => $order->id, 'reason' => 'Cliente desistió'],
    ]);

    app()->call([new CancelShipmentJob($event->id, $event->payload), 'handle']);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::CANCELLED)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->where('event_type', 'cancelled')->exists())->toBeTrue();
});

it('sincroniza escaneos de PDA de forma idempotente', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = operationsShipment();
    $scanId = (string) Str::uuid();

    $this->postJson('/api/v1/operations/scans/sync', [
        'device_id' => 'PDA-LIM-01',
        'scans' => [[
            'scan_id' => $scanId,
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_RECEPTION,
            'scanned_at' => now()->toIso8601String(),
            'latitude' => -12.0464,
            'longitude' => -77.0428,
        ]],
    ])->assertOk()
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.items.0.status', 'accepted');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED)
        ->and(Scan::query()->where('scan_id', $scanId)->exists())->toBeTrue();

    // El mismo scan_id no vuelve a mover el estado.
    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => $scanId,
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_RECEPTION,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()
        ->assertJsonPath('data.duplicated', 1)
        ->assertJsonPath('data.accepted', 0);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED);
});

it('rechaza un escaneo de embarque sin manifiesto', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = operationsShipment(['status' => ShipmentStatus::RECEIVED]);

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_EMBARK,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()
        ->assertJsonPath('data.rejected', 1)
        ->assertJsonPath('data.items.0.error_code', 'EMBARK_REQUIRED');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED);
});

it('expone el historial de tracking del envío', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = operationsShipment(['status' => ShipmentStatus::RECEIVED]);

    TrackingEvent::create([
        'shipment_id' => $shipment->id,
        'order_id' => $shipment->order_id,
        'event_type' => 'received',
        'status' => ShipmentStatus::RECEIVED,
        'agency_id' => 1,
        'created_at' => now(),
    ]);

    $this->getJson("/api/v1/operations/tracking/{$shipment->ticket_code}")
        ->assertOk()
        ->assertJsonPath('data.ticket_code', $shipment->ticket_code)
        ->assertJsonPath('data.events.0.event_type', 'received');
});

it('abre y cierra un conteo de inventario con diferencias', function () {
    $user = adminUser();
    $this->actingAs($user);

    $found = operationsShipment([
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    $missing = operationsShipment([
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    $count = $this->postJson('/api/v1/operations/inventory-counts', ['agency_id' => 1])
        ->assertCreated()
        ->json('data');

    $this->postJson("/api/v1/operations/inventory-counts/{$count['id']}/finish", [
        'items' => [
            ['shipment_id' => $found->id, 'found' => true],
            ['shipment_id' => $missing->id, 'found' => false, 'motive' => 'No estaba en estantería'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.missing', 1)
        ->assertJsonPath('data.found', 1);

    expect(OutboxEvent::query()->where('event_type', 'inventory.counted')->count())->toBe(1);
});

it('no permite dos conteos abiertos en la misma agencia', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->postJson('/api/v1/operations/inventory-counts', ['agency_id' => 1])->assertCreated();

    $this->postJson('/api/v1/operations/inventory-counts', ['agency_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVENTORY_ALREADY_OPEN');
});

it('no deja embarcar un envío que aún no se recibió', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = operationsShipment();

    $this->postJson('/api/v1/operations/embarks', [
        'agency_id' => 1,
        'vehicle_id' => 10,
        'driver_id' => 20,
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_SHIPMENT_TRANSITION');
});

it('aplica el ámbito de agencia a las operaciones', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('warehouse-operator');
    $user->agencies()->attach(1);
    $this->actingAs($user);

    $shipment = operationsShipment(['origin_agency_id' => 2, 'current_location_agency_id' => 2]);

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 2,
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ])->assertStatus(403)
        ->assertJsonPath('data.error_code', 'AGENCY_OUT_OF_SCOPE');
});
