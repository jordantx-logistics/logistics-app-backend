<?php

use Illuminate\Support\Str;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Services\ShipmentStatus;

/*
|--------------------------------------------------------------------------
| Validaciones de Operaciones (Fase 4)
|--------------------------------------------------------------------------
|
| Los filtros de estado se validan contra el catǭlogo del backend y los
| payloads de PDA/entrega contra el contrato. `per_page` no se rechaza: se
| recorta a 1..100 en el controlador.
|
*/

it('rechaza estados desconocidos en los filtros de listado', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/operations/shipments?status=wat')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->getJson('/api/v1/operations/shipments?status=received,wat')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->getJson('/api/v1/operations/deliveries?status=wat')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->getJson('/api/v1/operations/inventory-counts?status=wat')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $shipment = Shipment::factory()->create([
        'status' => ShipmentStatus::RECEIVED,
        'origin_agency_id' => 1,
        'destination_agency_id' => 2,
    ]);

    $this->getJson('/api/v1/operations/agencies/1/inventory?status=wat')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('recorta per_page al mǭximo del contrato en vez de rechazarlo', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/operations/shipments?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);

    $this->getJson('/api/v1/operations/deliveries?per_page=0')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1);
});

it('valida el payload de recepci��n y sus gu��as repetidas', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->postJson('/api/v1/operations/receptions', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['agency_id', 'items']);

    $this->postJson('/api/v1/operations/receptions', [
        'agency_id' => 1,
        'items' => [
            ['ticket_code' => 'ABCD1234'],
            ['ticket_code' => 'ABCD1234'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('items.0.ticket_code');
});

it('valida el manifiesto y sus gu��as repetidas', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->postJson('/api/v1/operations/embarks', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['agency_id', 'vehicle_id', 'driver_id', 'items']);

    $this->postJson('/api/v1/operations/embarks', [
        'agency_id' => 1,
        'vehicle_id' => 10,
        'driver_id' => 20,
        'items' => [
            ['ticket_code' => 'ABCD1234'],
            ['ticket_code' => 'ABCD1234'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

it('valida las fechas de los filtros y el rango de per_page', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/operations/shipments?created_from=2026-10-31&created_to=2026-10-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('created_to');

    $this->getJson('/api/v1/operations/receptions?received_from=2026-10-31&received_to=2026-10-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('received_to');

    $this->getJson('/api/v1/operations/embarks?departed_from=2026-10-31&departed_to=2026-10-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('departed_to');
});

it('valida la evidencia de entrega y el lote de escaneos del PDA', function () {
    $user = adminUser();
    $this->actingAs($user);

    $this->postJson('/api/v1/operations/deliveries/1/complete', [
        'recipient_name' => 'Ana PǸrez',
        'recipient_document' => '12345678',
        'signature_path' => 'tmp/signature.png',
        'photo_path' => 'deliveries/1/photo.jpg',
        'latitude' => -12.0464,
        'longitude' => -77.0428,
        'captured_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['signature_path']);

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => 'ABCD1234',
            'scan_type' => 'delivery',
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertOk()
        ->assertJsonPath('data.rejected', 1)
        ->assertJsonPath('data.items.0.error_code', 'SCAN_TYPE_NOT_SUPPORTED');

    $this->postJson('/api/v1/operations/scans/sync', [
        'scans' => [[
            'scan_id' => (string) Str::uuid(),
            'ticket_code' => 'ABCD1234',
            'scan_type' => 'wat',
            'scanned_at' => now()->toIso8601String(),
        ]],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['scans.0.scan_type']);
});
