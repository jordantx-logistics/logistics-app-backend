<?php

use App\Models\User;
use Illuminate\Support\Str;
use Src\Customer\Models\Person;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = auth('api')->login($this->user);
    $this->headers = ['Authorization' => "Bearer {$this->token}"];

    $this->sender = Person::factory()->create();
    $this->receiver = Person::factory()->create();
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
