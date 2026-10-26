<?php

use Illuminate\Support\Facades\Cache;
use Src\Logistics\Location\Models\Location;
use Src\Logistics\Location\Models\Zone;
use Src\Logistics\Tariff\Adapters\EloquentTariffDirectory;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Shared\Contracts\Metrics;
use Src\Shared\Contracts\TariffDirectory;

/*
|--------------------------------------------------------------------------
| Tarifarios (Fase 3)
|--------------------------------------------------------------------------
|
| Cubre CRUD, las invariantes de definición (alcance, vigencia, tramos) y la
| detección de solapes entre tarifarios activos del mismo alcance.
|
*/

function tariffPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'TAR-NAC-01',
        'name' => 'Nacional estándar',
        'service_type' => 'standard',
        'scope_type' => 'national',
        'base_price' => 5,
        'price_per_kg' => 2.5,
        'price_per_m3' => 0,
        'min_charge' => 10,
        'max_weight_kg' => 100,
        'volumetric_factor' => 200,
        'priority' => 0,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'brackets' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
});

it('crea un tarifario nacional con tramos y los devuelve ordenados', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'brackets' => [
                ['from_kg' => 20, 'to_kg' => 50, 'fixed_fee' => 20, 'price_per_kg' => 2],
                ['from_kg' => 0, 'to_kg' => 20, 'fixed_fee' => 8, 'price_per_kg' => 3],
            ],
        ]))
        ->assertStatus(201)
        ->assertJsonPath('data.code', 'TAR-NAC-01')
        ->assertJsonPath('data.scope_type', 'national')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonCount(2, 'data.brackets')
        ->assertJsonPath('data.brackets.0.from_kg', '0.000')
        ->assertJsonPath('data.brackets.1.from_kg', '20.000')
        ->assertJsonPath('data.valid_from', '2026-01-01');
});

it('rechaza una vigencia invertida', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'valid_from' => '2026-10-01',
            'valid_to' => '2026-09-01',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_VALIDITY_INVALID');
});

it('rechaza un alcance incoherente con el par de zonas o ubicaciones', function () {
    $zone = Zone::factory()->create();

    // `national` no lleva par de zonas.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'origin_zone_id' => $zone->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_SCOPE_INVALID');

    // `zone_pair` exige el par completo.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'scope_type' => 'zone_pair',
            'origin_zone_id' => $zone->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_SCOPE_INVALID');
});

it('valida que las zonas y ubicaciones existan (por puerto, sin FK)', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'scope_type' => 'zone_pair',
            'origin_zone_id' => 999999,
            'destination_zone_id' => 999999,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['origin_zone_id', 'destination_zone_id'], 'data');
});

it('rechaza tramos solapados o fuera del peso máximo', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'brackets' => [
                ['from_kg' => 0, 'to_kg' => 30, 'fixed_fee' => 5, 'price_per_kg' => 3],
                ['from_kg' => 20, 'to_kg' => 50, 'fixed_fee' => 5, 'price_per_kg' => 2],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_BRACKETS_INVALID');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'brackets' => [
                ['from_kg' => 0, 'to_kg' => 30, 'fixed_fee' => 5, 'price_per_kg' => 3],
                ['from_kg' => 500, 'to_kg' => 600, 'fixed_fee' => 5, 'price_per_kg' => 1],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'TARIFF_BRACKETS_INVALID');
});

it('no admite dos tarifarios activos solapados del mismo alcance', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload())
        ->assertStatus(201);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'code' => 'TAR-NAC-02',
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-12-31',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'TARIFF_OVERLAP');

    // Otro alcance (par de zonas) sí convive con el nacional.
    $origin = Zone::factory()->create();
    $destination = Zone::factory()->create();

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/tariffs', tariffPayload([
            'code' => 'TAR-ZON-01',
            'scope_type' => 'zone_pair',
            'origin_zone_id' => $origin->id,
            'destination_zone_id' => $destination->id,
            'base_price' => 8,
            'price_per_kg' => 1.5,
        ]))
        ->assertStatus(201);
});

it('edita el tarifario reemplazando sus tramos y corrigiendo el alcance', function () {
    $tariff = Tariff::factory()->create(['scope_type' => Tariff::SCOPE_ZONE_PAIR]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/logistics/tariffs/{$tariff->id}", [
            'code' => $tariff->code,
            'name' => 'Nuevo nombre',
            'service_type' => 'express',
            'scope_type' => 'national',
            'base_price' => 6,
            'price_per_kg' => 3,
            'min_charge' => 12,
            'max_weight_kg' => 200,
            'valid_from' => '2026-02-01',
            'brackets' => [
                ['from_kg' => 0, 'to_kg' => 40, 'fixed_fee' => 10, 'price_per_kg' => 2],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nuevo nombre')
        ->assertJsonPath('data.service_type', 'express')
        ->assertJsonPath('data.scope_type', 'national')
        ->assertJsonCount(1, 'data.brackets');

    // El alcance nacional limpia el par de zonas viejo (se funde con el payload).
    expect($tariff->refresh()->origin_zone_id)->toBeNull()
        ->and($tariff->destination_zone_id)->toBeNull();
});

it('desactiva un tarifario en vez de borrarlo', function () {
    $tariff = Tariff::factory()->create();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/logistics/tariffs/{$tariff->id}")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(Tariff::query()->whereKey($tariff->id)->exists())->toBeTrue();
});

it('lista y filtra tarifarios por servicio y alcance', function () {
    Tariff::factory()->create(['code' => 'TAR-A', 'scope_type' => Tariff::SCOPE_NATIONAL]);
    Tariff::factory()->create([
        'code' => 'TAR-B',
        'service_type' => Tariff::SERVICE_EXPRESS,
        'scope_type' => Tariff::SCOPE_ZONE_PAIR,
        'origin_zone_id' => Zone::factory(),
        'destination_zone_id' => Zone::factory(),
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/tariffs?service_type=express')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'TAR-B')
        ->assertJsonCount(0, 'data.0.brackets');
});

it('exige tariffs.manage', function () {
    $user = adminUser();
    $user->removeRole('admin')->assignRole('counter');

    $this->withHeaders(tokenHeadersFor($user))
        ->getJson('/api/v1/logistics/tariffs')
        ->assertStatus(403);
});

it('resuelve el tarifario por alcance: ubicación > zonas > nacional', function () {
    $origin = Location::factory()->create();
    $destination = Location::factory()->create();

    $national = Tariff::factory()->create([
        'code' => 'TAR-NAC',
        'scope_type' => Tariff::SCOPE_NATIONAL,
        'base_price' => 10,
    ]);

    $zonePair = Tariff::factory()->create([
        'code' => 'TAR-ZON',
        'scope_type' => Tariff::SCOPE_ZONE_PAIR,
        'origin_zone_id' => $origin->zone_id,
        'destination_zone_id' => $destination->zone_id,
        'base_price' => 20,
    ]);

    $districtPair = Tariff::factory()->create([
        'code' => 'TAR-DIS',
        'scope_type' => Tariff::SCOPE_DISTRICT_PAIR,
        'origin_location_id' => $origin->id,
        'destination_location_id' => $destination->id,
        'base_price' => 30,
    ]);

    $directory = app(TariffDirectory::class);

    expect($directory->findForRoute('standard', $origin->zone_id, $destination->zone_id, $origin->id, $destination->id)?->id)
        ->toBe($districtPair->id)
        ->and($directory->findForRoute('standard', $origin->zone_id, $destination->zone_id, null, null)?->id)
        ->toBe($zonePair->id)
        ->and($directory->findForRoute('standard', null, null, null, null)?->id)
        ->toBe($national->id)
        ->and($directory->findForRoute('express', null, null, null, null))
        ->toBeNull();
});

it('cachea el tarifario como array y cuenta hits y misses', function () {
    $tariff = Tariff::factory()->create(['code' => 'TAR-CACHE']);

    $metrics = new class implements Metrics
    {
        /** @var list<array{name: string, labels: array<string, string|int>, by: float}> */
        public array $increments = [];

        public function increment(string $name, array $labels = [], float $by = 1, string $help = ''): void
        {
            $this->increments[] = ['name' => $name, 'labels' => $labels, 'by' => $by];
        }

        public function gauge(string $name, float $value, array $labels = [], string $help = ''): void {}
    };

    $directory = new EloquentTariffDirectory($metrics);

    $first = $directory->findForRoute('standard', null, null, null, null);
    $second = $directory->findForRoute('standard', null, null, null, null);

    expect($first?->id)->toBe($tariff->id)
        ->and($second?->id)->toBe($tariff->id)
        ->and(array_column($metrics->increments, 'name'))
        ->toBe(['tariff_cache_misses_total', 'tariff_cache_hits_total'])
        ->and(Cache::tags(['tariffs'])->get('tariff:standard:-:-:-:-'))->toBeArray();
});
