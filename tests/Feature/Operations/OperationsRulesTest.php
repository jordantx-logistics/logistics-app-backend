<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Src\Identity\Employee\Models\Employee;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Reception\Models\Reception;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Tracking\Models\Scan;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\ShipmentStatus;

function rulesShipment(array $attributes = []): Shipment
{
    return Shipment::factory()->create(array_merge([
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
        'current_location_agency_id' => 1,
        'status' => ShipmentStatus::RECEIVED,
        'order_id' => fake()->unique()->numberBetween(1, 1_000_000),
    ], $attributes));
}

it('no recepciona dos veces el mismo envío sin scan_id', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = rulesShipment();

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 1,
        'items' => [['ticket_code' => $shipment->ticket_code]],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'RECEPTION_ALREADY_DONE');
});

it('trata un scan_id repetido como duplicado, no como error', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = rulesShipment(['status' => ShipmentStatus::CREATED]);
    $scanId = (string) Str::uuid();

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 1,
        'items' => [['ticket_code' => $shipment->ticket_code, 'scan_id' => $scanId]],
    ])->assertCreated()->assertJsonPath('data.accepted', 1);

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 1,
        'items' => [['ticket_code' => $shipment->ticket_code, 'scan_id' => $scanId]],
    ])->assertCreated()
        ->assertJsonPath('data.duplicated', 1)
        ->assertJsonPath('data.accepted', 0);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::RECEIVED)
        ->and(OutboxEvent::query()->where('event_type', 'shipment.received')->count())->toBe(1);
});

it('conserva un escaneo tardío como evidencia sin retroceder el estado', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = rulesShipment(['status' => ShipmentStatus::EMBARKED]);
    $scanId = (string) Str::uuid();

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => $scanId,
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_RECEPTION,
            'scanned_at' => now()->subHour()->toIso8601String(),
        ]],
    ])->assertOk()
        ->assertJsonPath('data.rejected', 1)
        ->assertJsonPath('data.items.0.error_code', 'INVALID_SHIPMENT_TRANSITION');

    $scan = Scan::query()->where('scan_id', $scanId)->first();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::EMBARKED)
        ->and($scan)->not->toBeNull()
        ->and($scan->payload['rejected'])->toBeTrue()
        ->and($scan->payload['error_code'])->toBe('INVALID_SHIPMENT_TRANSITION');
});

it('recorre los escaneos de embarque, desembarque y salida a reparto', function () {
    $user = adminUser();
    $this->actingAs($user);

    $shipment = rulesShipment();
    $embark = Embark::factory()->create(['agency_id' => 1]);

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_EMBARK,
            'embark_id' => $embark->id,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()->assertJsonPath('data.accepted', 1);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::EMBARKED);

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_DISEMBARK,
            'embark_id' => $embark->id,
            'agency_id' => 2,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()->assertJsonPath('data.accepted', 1);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::DISEMBARKED)
        ->and($shipment->current_location_agency_id)->toBe(2);

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => $shipment->ticket_code,
            'scan_type' => Scan::TYPE_OUT_FOR_DELIVERY,
            'agency_id' => 2,
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()->assertJsonPath('data.accepted', 1);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::OUT_FOR_DELIVERY)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->count())->toBe(3);
});

it('exige motivo también cuando aparece un envío que no se esperaba', function () {
    $user = adminUser();
    $this->actingAs($user);

    $unexpected = rulesShipment([
        'current_location_agency_id' => 2,
        'status' => ShipmentStatus::RECEIVED,
    ]);

    $count = $this->postJson('/api/v1/operations/inventory-counts', ['agency_id' => 1])
        ->assertCreated()
        ->json('data');

    $this->postJson("/api/v1/operations/inventory-counts/{$count['id']}/finish", [
        'items' => [['shipment_id' => $unexpected->id, 'found' => true]],
    ])->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVENTORY_MOTIVE_REQUIRED');

    $this->postJson("/api/v1/operations/inventory-counts/{$count['id']}/finish", [
        'items' => [['shipment_id' => $unexpected->id, 'found' => true, 'motive' => 'Paquete mal ubicado']],
    ])->assertOk()->assertJsonPath('data.unexpected', 1);
});

it('devuelve el envío al agotar los intentos de entrega', function () {
    config(['operations.delivery_max_attempts' => 3]);

    $user = adminUser();
    $this->actingAs($user);

    $shipment = rulesShipment([
        'current_location_agency_id' => 2,
        'status' => ShipmentStatus::DISEMBARKED,
    ]);
    $courier = Employee::factory()->create(['agency_id' => 2, 'status' => Employee::STATUS_ACTIVE]);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $delivery = $this->postJson('/api/v1/operations/deliveries/assign', [
            'agency_id' => 2,
            'courier_id' => $courier->id,
            'shipment_ids' => [$shipment->id],
        ])->assertCreated()->json('data.0');

        expect($delivery['attempt'])->toBe($attempt);

        $this->postJson("/api/v1/operations/deliveries/{$delivery['id']}/fail", [
            'failure_reason' => 'Destinatario ausente',
        ])->assertOk()
            ->assertJsonPath('data.status', $attempt === 3 ? Delivery::STATUS_RETURNED : Delivery::STATUS_FAILED);

        expect($shipment->refresh()->status)->toBe(
            $attempt === 3 ? ShipmentStatus::RETURNED : ShipmentStatus::DELIVERY_FAILED
        );
    }

    $event = OutboxEvent::query()->where('event_type', 'delivery.failed')->latest('id')->first();

    expect($event->payload['returned'])->toBeTrue()
        ->and(OutboxEvent::query()->where('event_type', 'delivery.failed')->count())->toBe(3)
        ->and(TrackingEvent::query()->where('shipment_id', $shipment->id)->where('event_type', 'returned')->count())->toBe(1);
});

it('lista envíos, embarks, recepciones y entregas con filtros', function () {
    $user = adminUser();
    $this->actingAs($user);

    $received = rulesShipment(['status' => ShipmentStatus::RECEIVED, 'origin_agency_id' => 1]);
    rulesShipment([
        'status' => ShipmentStatus::EMBARKED,
        'origin_agency_id' => 2,
        'current_location_agency_id' => 2,
    ]);
    Embark::factory()->create(['agency_id' => 1]);
    Reception::factory()->create(['agency_id' => 1]);
    Delivery::factory()->create(['agency_id' => 1, 'status' => Delivery::STATUS_ASSIGNED]);

    $this->getJson('/api/v1/operations/shipments?status=received&agency=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.ticket_code', $received->ticket_code);

    $this->getJson("/api/v1/operations/shipments/{$received->ticket_code}")
        ->assertOk()
        ->assertJsonPath('data.status', ShipmentStatus::RECEIVED);

    $this->getJson('/api/v1/operations/embarks?agency=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/operations/receptions?agency=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/operations/deliveries?status=assigned&agency=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/operations/agencies/1/inventory')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('registra el comando de particiones y lo agenda a diario', function () {
    $this->artisan('tracking:create-next-partition')
        ->expectsOutput('tracking_events is not partitioned on this driver; nothing to do.')
        ->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'tracking:create-next-partition'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 2 * * *');
});
