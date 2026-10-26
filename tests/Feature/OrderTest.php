<?php

use Illuminate\Support\Facades\DB;
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

/*
|--------------------------------------------------------------------------
| Filtros del listado (cierre de Fase 3)
|--------------------------------------------------------------------------
*/

it('filtra el listado por estado', function () {
    $pendiente = makeOrder(['status' => 'pending']);
    $confirmada = makeOrder(['status' => 'confirmed']);
    makeOrder(['status' => 'delivered']);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?status[]=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pendiente->id)
        ->assertJsonPath('meta.total', 1);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?status=pending,confirmed')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $confirmada->id)
        ->assertJsonPath('data.1.id', $pendiente->id)
        ->assertJsonPath('meta.total', 2);
});

it('filtra el listado por agencia de origen y destino', function () {
    $desdeOrigen = makeOrder([
        'origin_agency_id' => $this->origin->id,
        'destination_agency_id' => $this->destination->id,
    ]);
    makeOrder([
        'origin_agency_id' => $this->destination->id,
        'destination_agency_id' => $this->origin->id,
    ]);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?origin_agency_id={$this->origin->id}&destination_agency_id={$this->destination->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $desdeOrigen->id);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?agency={$this->origin->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filtra por documento del remitente y del destinatario', function () {
    $orden = makeOrder([
        'sender_id' => $this->sender->id,
        'receiver_id' => $this->receiver->id,
    ]);
    makeOrder([
        'sender_id' => $this->receiver->id,
        'receiver_id' => $this->sender->id,
    ]);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?sender_document={$this->sender->document_number}&sender_document_type={$this->sender->document_type}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $orden->id);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?receiver_document={$this->receiver->document_number}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $orden->id);
});

it('filtra por prefijo de ticket y por código exacto', function () {
    $orden = makeOrder();

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?ticket=ORD-')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $orden->id);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?ticket={$orden->ticket_code}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $orden->id);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?ticket_code={$orden->ticket_code}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $orden->id);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders?ticket_number={$orden->ticket_number}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $orden->id);
});

it('filtra por rango de fechas de creación', function () {
    $vieja = makeOrder();
    $reciente = makeOrder();

    DB::table('orders')->where('id', $vieja->id)->update(['created_at' => now()->subDays(5)]);
    DB::table('orders')->where('id', $reciente->id)->update(['created_at' => now()]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?created_from='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reciente->id);
});

it('rechaza un estado inexistente en el filtro', function () {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/orders?status[]=flying')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

/*
|--------------------------------------------------------------------------
| Edición de la orden (cierre de Fase 3)
|--------------------------------------------------------------------------
*/

it('edita la descripción sin recalcular el precio', function () {
    $orden = makeOrder([
        'origin_agency_id' => $this->origin->id,
        'destination_agency_id' => $this->destination->id,
        'total_amount' => 30,
        'description' => 'Original',
    ]);

    $response = $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", ['description' => 'Actualizada']);

    $response->assertOk()
        ->assertJsonPath('data.description', 'Actualizada')
        ->assertJsonPath('data.total_amount', '30.00')
        ->assertJsonPath('data.version', 2);
});

it('recalcula el precio cuando cambia el peso', function () {
    $orden = makeOrder([
        'origin_agency_id' => $this->origin->id,
        'destination_agency_id' => $this->destination->id,
        'weight_kg' => 10,
        'volume_m3' => 0,
        'total_amount' => 30,
    ]);

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", ['weight_kg' => 20])
        ->assertOk()
        // Base 5.00 + 20 * 2.50 = 55.00
        ->assertJsonPath('data.total_amount', '55.00')
        ->assertJsonPath('data.weight_kg', '20.000');
});

it('permite limpiar campos nullable', function () {
    $orden = makeOrder(['description' => 'Con descripción']);

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('data.description', null);
});

it('rechaza editar una orden que ya salió de la agencia', function () {
    $orden = makeOrder(['status' => 'in_transit']);

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", ['description' => 'Tarde'])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'ORDER_NOT_EDITABLE');
});

it('rechaza una edición sin campos', function () {
    $orden = makeOrder();

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", [])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'NO_UPDATE_DATA');
});

it('exige orders.update para editar una orden', function () {
    $orden = makeOrder();

    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('cashier');

    $this->actingAs($user)
        ->patchJson("/api/v1/logistics/orders/{$orden->id}", ['description' => 'Sin permiso'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Eventos de confirmación y cancelación (cierre de Fase 3)
|--------------------------------------------------------------------------
*/

it('emite order.confirmed en la misma transacción al confirmar', function () {
    $orden = makeOrder();

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/logistics/orders/{$orden->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $this->assertDatabaseHas('outbox_events', [
        'event_type' => 'order.confirmed',
    ]);

    $event = DB::table('outbox_events')->where('event_type', 'order.confirmed')->sole();
    $payload = json_decode($event->payload, true);

    expect($payload['order_id'])->toBe($orden->id)
        ->and($payload['ticket_number'])->toBe($orden->ticket_number)
        ->and($payload['status'])->toBe('confirmed')
        ->and($payload['confirmed_by'])->toBe($this->user->id);
});

it('emite order.cancelled con el motivo al cancelar', function () {
    $orden = makeOrder();

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/logistics/orders/{$orden->id}/cancel", ['reason' => 'Cliente desistió'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $event = DB::table('outbox_events')->where('event_type', 'order.cancelled')->sole();
    $payload = json_decode($event->payload, true);

    expect($payload['order_id'])->toBe($orden->id)
        ->and($payload['reason'])->toBe('Cliente desistió')
        ->and($payload['cancelled_by'])->toBe($this->user->id);
});
