<?php

use Illuminate\Support\Str;
use Src\Customer\Models\Customer;
use Src\Logistics\Order\Models\Order;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);

    // Desde la Fase 3 el remitente/destinatario es un **cliente** (`customers`),
    // no una persona: el puerto `CustomerDirectory` resuelve contra el agregado.
    $this->sender = Customer::factory()->create();
    $this->receiver = Customer::factory()->create();
    $this->origin = Agency::factory()->create();
    $this->destination = Agency::factory()->create();
});

it('creates an order through the usecase properly', function () {
    $idempotencyKey = Str::uuid()->toString();

    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', [
            'idempotency_key' => $idempotencyKey,
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id,
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'weight_kg' => 10,  // Base 5.00 + 10 * 2.50 = 30.00
            'volume_m3' => 0,
            'description' => 'Test package',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'ticket_number',
                'ticket_code',
                'status',
                'total_amount',
            ],
        ])
        ->assertJsonPath('data.total_amount', '30.00');

    // Assert tickets generator worked
    $this->assertDatabaseHas('orders', [
        'idempotency_key' => $idempotencyKey,
        'status' => 'pending',
    ]);

    // Assert Outbox Event was dispatched
    $this->assertDatabaseHas('outbox_events', [
        'event_type' => 'order.created',
    ]);
});

it('blocks duplicate idempotency keys', function () {
    $idempotencyKey = Str::uuid()->toString();
    $payload = [
        'idempotency_key' => $idempotencyKey,
        'sender_id' => $this->sender->id,
        'receiver_id' => $this->receiver->id,
        'origin_agency_id' => $this->origin->id,
        'destination_agency_id' => $this->destination->id,
        'weight_kg' => 10,
    ];

    $this->withHeaders($this->headers)->postJson('/api/v1/logistics/orders', $payload)->assertStatus(201);

    // Duplicate call
    $response = $this->withHeaders($this->headers)->postJson('/api/v1/logistics/orders', $payload);

    $response->assertStatus(409)
        ->assertJsonPath('data.error_code', 'DUPLICATE_ORDER');
});

it('blocks rules invalidation for overweight', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', [
            'idempotency_key' => Str::uuid()->toString(),
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id,
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'weight_kg' => 600, // Rules max is 500
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('data.error_code', 'WEIGHT_EXCEEDED');
});

/*
|--------------------------------------------------------------------------
| Listado de órdenes (Fase 1 §5.6)
|--------------------------------------------------------------------------
|
| El listado es nuevo: permiso propio (`orders.view`, separado de
| `orders.create`), ámbito de agencia y paginación estándar del spec
| (`data` + `meta` + `links`).
|
*/

/** Crea una orden directamente, sin pasar por el POST (ya cubierto arriba). */
function makeOrder(array $attributes = []): Order
{
    static $sequence = 0;

    return Order::create(array_merge([
        'idempotency_key' => Str::uuid()->toString(),
        'sender_id' => 1,
        'receiver_id' => 1,
        'weight_kg' => 10,
        'total_amount' => 30,
        'status' => 'pending',
        'created_by' => 1,
        // Agencias por defecto distintas en cada llamada, para que un test que
        // no pase `origin_agency_id` no dependa de fixtures de otro.
        'origin_agency_id' => ++$sequence,
        'destination_agency_id' => ++$sequence,
    ], $attributes));
}

it('lista las órdenes paginadas, de la más reciente a la más antigua', function () {
    makeOrder(['origin_agency_id' => $this->origin->id, 'destination_agency_id' => $this->destination->id]);
    $segunda = makeOrder(['origin_agency_id' => $this->origin->id, 'destination_agency_id' => $this->destination->id]);
    $tercera = makeOrder(['origin_agency_id' => $this->origin->id, 'destination_agency_id' => $this->destination->id]);

    $response = $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $tercera->id)
        ->assertJsonPath('data.1.id', $segunda->id)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id', 'ticket_number', 'ticket_code', 'status', 'weight_kg',
                    'total_amount', 'sender_id', 'receiver_id',
                    'origin_agency_id', 'destination_agency_id', 'created_at',
                ],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
            'links' => ['first', 'last', 'prev', 'next'],
        ]);
});

it('limita per_page al máximo permitido', function () {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?per_page=1000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('acota el listado a las órdenes que tocan las agencias del usuario', function () {
    $agenciaA = Agency::factory()->create();
    $agenciaB = Agency::factory()->create();

    $saleDeA = makeOrder(['origin_agency_id' => $agenciaA->id, 'destination_agency_id' => $agenciaB->id]);
    $llegaA = makeOrder(['origin_agency_id' => $agenciaB->id, 'destination_agency_id' => $agenciaA->id]);
    makeOrder(['origin_agency_id' => $agenciaB->id, 'destination_agency_id' => $agenciaB->id]);

    $supervisor = adminUser();
    $supervisor->removeRole('admin');
    $supervisor->assignRole('agency-supervisor');
    $supervisor->agencies()->attach($agenciaA);

    $this->actingAs($supervisor)
        ->getJson('/api/v1/logistics/orders')
        ->assertOk()
        // Origen o destino: la orden cuenta para las dos agencias que toca.
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $llegaA->id)
        ->assertJsonPath('data.1.id', $saleDeA->id);
});

it('no deja ver órdenes a un usuario operativo sin agencia asignada', function () {
    makeOrder(['origin_agency_id' => $this->origin->id, 'destination_agency_id' => $this->destination->id]);

    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('agency-supervisor');

    $this->actingAs($user)
        ->getJson('/api/v1/logistics/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('responde 403 al listar órdenes sin orders.view', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('cashier'); // Sin permisos todavía

    $this->actingAs($user)
        ->getJson('/api/v1/logistics/orders')
        ->assertForbidden();
});

it('exige sesión para listar órdenes', function () {
    $this->getJson('/api/v1/logistics/orders')->assertUnauthorized();
});
