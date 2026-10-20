<?php

use Illuminate\Support\Str;
use Src\Customer\Models\Customer;
use Src\Logistics\Location\Models\AgencyCoverage;
use Src\Logistics\Location\Models\Location;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Organization\Models\Agency;

/*
|--------------------------------------------------------------------------
| Flujo de la orden (Fase 3 B.4-B.5)
|--------------------------------------------------------------------------
|
| Cotización sin persistir, alta con snapshot de precio, peso volumétrico,
| recargo de reparto, máquina de estados e histórico. El motor legacy se prueba
| aparte (OrderTest, con `PRICING_ENGINE=legacy` fijado en phpunit.xml).
|
*/

function tariffAgency(): Agency
{
    return Agency::factory()->create();
}

function nationalTariff(array $overrides = [], array $brackets = []): Tariff
{
    $tariff = Tariff::factory()->create(array_merge([
        'base_price' => 5,
        'price_per_kg' => 2.5,
        'min_charge' => 10,
        'max_weight_kg' => 100,
        'volumetric_factor' => 200,
    ], $overrides));

    foreach ($brackets as $bracket) {
        $tariff->brackets()->create($bracket);
    }

    return $tariff;
}

function orderPayload(array $overrides = []): array
{
    return array_merge([
        'idempotency_key' => Str::uuid()->toString(),
        'sender_id' => Customer::factory()->create()->id,
        'receiver_id' => Customer::factory()->create()->id,
        'weight_kg' => 10,
        'volume_m3' => 0,
    ], $overrides);
}

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
    $this->origin = tariffAgency();
    $this->destination = tariffAgency();
});

it('cotiza con el tarifario, el peso volumétrico y los tramos', function () {
    config(['pricing.engine' => 'tariff']);

    $tariff = nationalTariff([], [
        ['from_kg' => 0, 'to_kg' => 20, 'fixed_fee' => 8, 'price_per_kg' => 3],
    ]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders/quote', [
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'weight_kg' => 10,
            'volume_m3' => 0.1, // 0.1 m³ × 200 = 20 kg facturables
        ])
        ->assertOk()
        ->assertJsonPath('data.engine', 'tariff')
        ->assertJsonPath('data.chargeable_weight_kg', 20)
        ->assertJsonPath('data.base_price', 5)
        ->assertJsonPath('data.weight_amount', 68) // 8 + (20 - 0) × 3
        ->assertJsonPath('data.minimum_adjustment', 0)
        ->assertJsonPath('data.total_amount', 73)
        ->assertJsonPath('data.tariff_id', $tariff->id);
});

it('usa el motor legacy cuando PRICING_ENGINE=legacy', function () {
    config(['pricing.engine' => 'legacy']);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders/quote', [
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'weight_kg' => 10,
            'volume_m3' => 0.1,
        ])
        ->assertOk()
        ->assertJsonPath('data.engine', 'legacy')
        ->assertJsonPath('data.total_amount', 35) // 5 + 25 + 5
        ->assertJsonPath('data.tariff_id', null);
});

it('rechaza la cotización si no hay tarifario vigente', function () {
    config(['pricing.engine' => 'tariff']);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders/quote', [
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'weight_kg' => 10,
        ])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_NOT_FOUND');
});

it('crea la orden con snapshot de precio y su primer estado', function () {
    config(['pricing.engine' => 'tariff']);

    $tariff = nationalTariff(['service_type' => 'express']);

    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', orderPayload([
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
            'service_type' => 'express',
            'payment_responsibility' => 'receiver',
        ]))
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.service_type', 'express')
        ->assertJsonPath('data.chargeable_weight_kg', '10.000')
        ->assertJsonPath('data.tariff_id', $tariff->id)
        ->assertJsonPath('data.total_amount', '30.00')
        ->assertJsonPath('data.pricing_snapshot.engine', 'tariff')
        ->assertJsonPath('data.pricing_snapshot.tariff_id', $tariff->id);

    $order = Order::query()->findOrFail($response->json('data.id'));

    expect($order->pricing_snapshot['chargeable_weight_kg'])->toBe(10)
        ->and($order->version)->toBe(1)
        ->and($order->statusHistory()->pluck('to_status')->all())->toBe(['pending']);
});

it('aplica el recargo de reparto y exige cobertura de la agencia destino', function () {
    config(['pricing.engine' => 'tariff']);

    nationalTariff();

    $covered = Location::factory()->create();
    $notCovered = Location::factory()->create();

    AgencyCoverage::create([
        'agency_id' => $this->destination->id,
        'location_id' => $covered->id,
        'allows_pickup' => true,
        'allows_delivery' => true,
        'delivery_surcharge' => 8,
    ]);

    $payload = [
        'origin_agency_id' => $this->origin->id,
        'destination_agency_id' => $this->destination->id,
        'weight_kg' => 10,
        'delivery_type' => 'home_delivery',
    ];

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders/quote', [...$payload, 'delivery_location_id' => $covered->id])
        ->assertOk()
        ->assertJsonPath('data.surcharge', 8)
        ->assertJsonPath('data.total_amount', 38);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders/quote', [...$payload, 'delivery_location_id' => $notCovered->id])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'DELIVERY_NOT_COVERED');
});

it('recalcula el precio al definir el reparto antes de confirmar', function () {
    config(['pricing.engine' => 'tariff']);

    nationalTariff();

    $location = Location::factory()->create();
    AgencyCoverage::create([
        'agency_id' => $this->destination->id,
        'location_id' => $location->id,
        'allows_pickup' => true,
        'allows_delivery' => true,
        'delivery_surcharge' => 12.5,
    ]);

    $order = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', orderPayload([
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
        ]))
        ->assertJsonPath('data.total_amount', '30.00')
        ->json('data');

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$order['id']}/delivery", [
            'delivery_type' => 'home_delivery',
            'delivery_location_id' => $location->id,
            'recipient_address' => 'Av. Los Alamos 123',
            'requires_signature' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_type', 'home_delivery')
        ->assertJsonPath('data.recipient_address', 'Av. Los Alamos 123')
        ->assertJsonPath('data.total_amount', '42.50')
        ->assertJsonPath('data.version', 2);
});

it('confirma, bloquea el reparto y cancela con motivo', function () {
    config(['pricing.engine' => 'tariff']);

    nationalTariff();

    $order = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', orderPayload([
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
        ]))
        ->json('data');

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/logistics/orders/{$order['id']}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.version', 2);

    // Tras confirmar, el precio está congelado.
    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/logistics/orders/{$order['id']}/delivery", [
            'delivery_type' => 'agency_pickup',
        ])
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'ORDER_ALREADY_CONFIRMED');

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/logistics/orders/{$order['id']}/cancel", ['reason' => 'Cliente desiste'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancelled_reason', 'Cliente desiste');

    // `cancelled` es terminal.
    $this->withHeaders($this->headers)
        ->postJson("/api/v1/logistics/orders/{$order['id']}/confirm")
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_STATUS_TRANSITION');

    $stored = Order::query()->findOrFail($order['id']);

    expect($stored->confirmed_at)->not->toBeNull()
        ->and($stored->cancelled_at)->not->toBeNull()
        ->and($stored->statusHistory()->pluck('to_status')->all())
        ->toBe(['pending', 'confirmed', 'cancelled']);
});

it('busca por ticket y respeta el ámbito de agencia', function () {
    config(['pricing.engine' => 'tariff']);

    nationalTariff();

    $order = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', orderPayload([
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
        ]))
        ->json('data');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/orders/by-ticket/{$order['ticket_code']}")
        ->assertOk()
        ->assertJsonPath('data.id', $order['id'])
        ->assertJsonCount(1, 'data.status_history');

    $otra = tariffAgency();
    $supervisor = adminUser();
    $supervisor->removeRole('admin')->assignRole('agency-supervisor');
    $supervisor->agencies()->attach($otra);

    $this->app['auth']->forgetGuards();

    $this->actingAs($supervisor)
        ->getJson("/api/v1/logistics/orders/{$order['id']}")
        ->assertNotFound();
});

it('exige orders.confirm y orders.cancel según el permiso', function () {
    nationalTariff();

    $order = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/orders', orderPayload([
            'origin_agency_id' => $this->origin->id,
            'destination_agency_id' => $this->destination->id,
        ]))
        ->json('data');

    $counter = adminUser();
    $counter->removeRole('admin')->assignRole('counter'); // sin confirm ni cancel
    $counter->agencies()->attach($this->origin);

    $this->app['auth']->forgetGuards();

    $this->actingAs($counter)
        ->postJson("/api/v1/logistics/orders/{$order['id']}/confirm")
        ->assertForbidden();

    $this->actingAs($counter)
        ->postJson("/api/v1/logistics/orders/{$order['id']}/cancel")
        ->assertForbidden();
});
