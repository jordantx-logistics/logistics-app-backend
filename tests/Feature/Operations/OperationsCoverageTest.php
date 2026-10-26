<?php

use Src\Identity\Employee\Models\Employee;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Inventory\Models\InventoryCount;
use Src\Operations\Inventory\Models\InventoryItem;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\EmbarkItem;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Operaciones: caminos de consulta y transiciones secundarias
|--------------------------------------------------------------------------
|
| Los flujos felices viven en OperationsFlowTest. Aquí se cubren los filtros,
| las consultas por id que devuelven 404, el arranque de una entrega y las
| ramas de error del desembarque, que son las que quedaban sin ejercitar.
|
*/

function coverageShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create(array_merge([
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::RECEIVED,
        'order_id' => fake()->unique()->numberBetween(1, 1_000_000),
    ], $attributes));
}

it('lista y muestra conteos de inventario con todos sus filtros', function () {
    $user = adminUser();
    $this->actingAs($user);

    $open = InventoryCount::factory()->create([
        'agency_id' => 1,
        'status' => InventoryCount::STATUS_OPEN,
        'created_at' => now()->subDay(),
    ]);

    InventoryItem::factory()->create([
        'inventory_count_id' => $open->id,
        'shipment_id' => coverageShipment()->id,
    ]);

    InventoryCount::factory()->create([
        'agency_id' => 2,
        'status' => InventoryCount::STATUS_FINISHED,
        'finished_at' => now(),
        'created_at' => now()->subMonth(),
    ]);

    $this->getJson('/api/v1/operations/inventory-counts?agency=1&status=open')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('data.0.items_count', 1);

    $this->getJson('/api/v1/operations/inventory-counts?created_from='.now()->subDays(2)->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/operations/inventory-counts?created_to='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson("/api/v1/operations/inventory-counts/{$open->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $open->id)
        ->assertJsonPath('data.items_count', 1)
        ->assertJsonPath('data.items.0.shipment_id', InventoryItem::query()->first()->shipment_id);

    $this->getJson('/api/v1/operations/inventory-counts/999999')
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'INVENTORY_NOT_FOUND');
});

it('lista y muestra entregas con filtros y arranca el reparto', function () {
    $user = adminUser();
    $this->actingAs($user);

    $assigned = Delivery::factory()->create([
        'agency_id' => 2,
        'courier_id' => 7,
        'status' => Delivery::STATUS_ASSIGNED,
        'ticket_code' => 'DELV0001',
    ]);

    Delivery::factory()->create([
        'agency_id' => 2,
        'courier_id' => 8,
        'status' => Delivery::STATUS_DELIVERED,
        'ticket_code' => 'DELV0002',
    ]);

    $this->getJson('/api/v1/operations/deliveries?agency=2&status=assigned&courier_id=7&ticket_code=delv0001')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $assigned->id);

    $this->getJson("/api/v1/operations/deliveries/{$assigned->id}")
        ->assertOk()
        ->assertJsonPath('data.status', Delivery::STATUS_ASSIGNED);

    $this->postJson("/api/v1/operations/deliveries/{$assigned->id}/start")
        ->assertOk()
        ->assertJsonPath('data.status', Delivery::STATUS_IN_PROGRESS);

    // Reintentar el arranque es idempotente.
    $this->postJson("/api/v1/operations/deliveries/{$assigned->id}/start")
        ->assertOk()
        ->assertJsonPath('data.status', Delivery::STATUS_IN_PROGRESS);

    $delivered = Delivery::factory()->create([
        'agency_id' => 2,
        'status' => Delivery::STATUS_DELIVERED,
    ]);

    $this->postJson("/api/v1/operations/deliveries/{$delivered->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_DELIVERY_TRANSITION');

    $this->getJson('/api/v1/operations/deliveries/999999')
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'DELIVERY_NOT_FOUND');

    $this->postJson('/api/v1/operations/deliveries/999999/start')
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'DELIVERY_NOT_FOUND');
});

it('filtra el inventario físico de una agencia por estado y búsqueda', function () {
    $user = adminUser();
    $this->actingAs($user);

    $received = coverageShipment([
        'ticket_code' => 'INV00001',
        'ticket_number' => 'ORD-2026-888001',
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    coverageShipment([
        'ticket_code' => 'INV00002',
        'ticket_number' => 'ORD-2026-888002',
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::EMBARKED,
    ]);

    coverageShipment([
        'ticket_code' => 'INV00003',
        'ticket_number' => 'ORD-2026-888003',
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::DELIVERED,
    ]);

    $this->getJson('/api/v1/operations/agencies/1/inventory?status=received&search=INV000')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ticket_code', $received->ticket_code);

    $this->getJson('/api/v1/operations/agencies/1/inventory?status=received,embarked')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/v1/operations/agencies/1/inventory?search=ord-2026-888002')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('desembarca de forma parcial, reporta duplicados y valida el manifiesto', function () {
    $user = adminUser();
    $this->actingAs($user);

    $first = coverageShipment(['status' => ShipmentStatus::RECEIVED]);
    $second = coverageShipment(['status' => ShipmentStatus::RECEIVED]);

    $embark = $this->postJson('/api/v1/operations/embarks', [
        'agency_id' => 1,
        'vehicle_id' => 10,
        'driver_id' => 20,
        'items' => [
            ['ticket_code' => $first->ticket_code],
            ['ticket_code' => $second->ticket_code],
        ],
    ])->assertCreated()->json('data.embark');

    $this->postJson("/api/v1/operations/embarks/{$embark['id']}/disembark", [
        'agency_id' => 2,
        'items' => [['ticket_code' => $first->ticket_code]],
    ])->assertOk()
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.duplicated', 0);

    $this->postJson("/api/v1/operations/embarks/{$embark['id']}/disembark", [
        'agency_id' => 2,
        'items' => [
            ['ticket_code' => $first->ticket_code],
            ['ticket_code' => $second->ticket_code],
        ],
    ])->assertOk()
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.duplicated', 1);

    expect($second->refresh()->status)->toBe(ShipmentStatus::DISEMBARKED);

    // Un manifiesto ya descargado por completo no vuelve a emitir el evento.
    $this->postJson("/api/v1/operations/embarks/{$embark['id']}/disembark", [
        'agency_id' => 2,
    ])->assertOk()
        ->assertJsonPath('data.accepted', 0)
        ->assertJsonPath('data.duplicated', 2);

    $this->postJson("/api/v1/operations/embarks/{$embark['id']}/disembark", [
        'agency_id' => 2,
        'items' => [['ticket_code' => 'NOEXIST1']],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'SHIPMENT_NOT_IN_EMBARK');

    $this->postJson('/api/v1/operations/embarks/999999/disembark', ['agency_id' => 2])
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'EMBARK_NOT_FOUND');

    $empty = Embark::factory()->create(['agency_id' => 1]);

    $this->postJson("/api/v1/operations/embarks/{$empty->id}/disembark", ['agency_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'EMBARK_HAS_NO_ITEMS');
});

it('rechaza desembarcar un envío que no está embarcado', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = coverageShipment(['status' => ShipmentStatus::RECEIVED]);
    $embark = Embark::factory()->create(['agency_id' => 1]);

    EmbarkItem::factory()->create([
        'embark_id' => $embark->id,
        'shipment_id' => $shipment->id,
        'ticket_code' => $shipment->ticket_code,
    ]);

    $this->postJson("/api/v1/operations/embarks/{$embark->id}/disembark", ['agency_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_SHIPMENT_TRANSITION');
});

it('valida el courier y el estado al asignar entregas', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = coverageShipment([
        'destination_agency_id' => 2,
        'current_location_agency_id' => 2,
        'status' => ShipmentStatus::DISEMBARKED,
    ]);
    $courier = Employee::factory()->create(['agency_id' => 2, 'status' => Employee::STATUS_ACTIVE]);

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => 999999,
        'shipment_ids' => [$shipment->id],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_COURIER');

    $otherCourier = Employee::factory()->create(['agency_id' => 3, 'status' => Employee::STATUS_ACTIVE]);

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $otherCourier->id,
        'shipment_ids' => [$shipment->id],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'COURIER_WRONG_AGENCY');

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [999999],
    ])->assertNotFound()
        ->assertJsonPath('data.error_code', 'SHIPMENT_NOT_FOUND');

    $wrongAgency = coverageShipment([
        'destination_agency_id' => 1,
        'current_location_agency_id' => 2,
        'status' => ShipmentStatus::DISEMBARKED,
    ]);

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [$wrongAgency->id],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'DELIVERY_WRONG_AGENCY');

    $received = coverageShipment([
        'destination_agency_id' => 2,
        'current_location_agency_id' => 2,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [$received->id],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_SHIPMENT_TRANSITION');

    $delivery = $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [$shipment->id],
    ])->assertCreated()->json('data.0');

    // El envío vuelve a estar disponible, pero su entrega sigue activa.
    $shipment->refresh()->update(['status' => ShipmentStatus::DISEMBARKED]);
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::DISEMBARKED)
        ->and(Delivery::query()->find($delivery['id'])->status)->toBe(Delivery::STATUS_ASSIGNED);

    $this->postJson('/api/v1/operations/deliveries/assign', [
        'agency_id' => 2,
        'courier_id' => $courier->id,
        'shipment_ids' => [$shipment->id],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'DELIVERY_ALREADY_ACTIVE');

    expect($delivery['attempt'])->toBe(1);
});
