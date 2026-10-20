<?php

use Database\Seeders\UbigeoSeeder;
use Src\Logistics\Location\Models\AgencyCoverage;
use Src\Logistics\Location\Models\Location;
use Src\Logistics\Location\Models\Zone;
use Src\Organization\Models\Agency;

/*
|--------------------------------------------------------------------------
| Zonas, ubicaciones y coberturas (Fase 3)
|--------------------------------------------------------------------------
|
| El padrón se siembra del CSV versionado: 25 zonas (una por departamento) y
| 1874 ubicaciones. Las dos filas del CSV sin código INEI (`NA`) se descartan
| porque el ubigeo INEI es la clave.
|
*/

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
});

it('siembra una zona por departamento y el padrón de ubicaciones', function () {
    $this->seed(UbigeoSeeder::class);
    $this->seed(UbigeoSeeder::class); // idempotente

    expect(Zone::query()->count())->toBe(25)
        ->and(Location::query()->count())->toBe(1874)
        ->and(Location::query()->distinct()->count('ubigeo'))->toBe(1874);

    $amazonas = Zone::query()->where('code', 'ZON-01')->sole();

    expect($amazonas->name)->toBe('AMAZONAS')
        ->and($amazonas->locations()->count())->toBe(84);

    $lima = Location::query()->where('ubigeo', '150101')->sole();

    expect($lima->district)->toBe('LIMA')
        ->and($lima->province)->toBe('LIMA')
        ->and($lima->department)->toBe('LIMA')
        ->and($lima->zone->code)->toBe('ZON-15');

    // El padrón original viene en Windows-1252: el seeder lo normaliza a UTF-8
    // (SQLite lo aceptaba igual, MySQL no).
    expect(Location::query()->where('ubigeo', '050605')->sole()->district)->toBe('CHAVIÑA')
        ->and(mb_check_encoding(Location::query()->where('ubigeo', '150101')->value('district'), 'UTF-8'))->toBeTrue();
});

it('lista las zonas con el número de ubicaciones', function () {
    $this->seed(UbigeoSeeder::class);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/zones')
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('data.0.code', 'ZON-01')
        ->assertJsonPath('data.0.name', 'AMAZONAS')
        ->assertJsonPath('data.0.locations_count', 84);
});

it('crea y edita una zona, y no repite el código', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/zones', ['code' => 'NOR-1', 'name' => 'Norte 1']);

    $response->assertStatus(201)
        ->assertJsonPath('data.code', 'NOR-1')
        ->assertJsonPath('data.is_active', true);

    $zone = Zone::query()->where('code', 'NOR-1')->sole();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/logistics/zones/{$zone->id}", ['code' => 'NOR-1', 'name' => 'Norte Uno'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Norte Uno');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/zones', ['code' => 'NOR-1', 'name' => 'Repetida'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('lista ubicaciones filtrando por texto, zona y con la zona en el recurso', function () {
    $this->seed(UbigeoSeeder::class);

    $response = $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/locations?q=LIMA&per_page=5');

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonStructure([
            'data' => [['id', 'ubigeo', 'department', 'province', 'district', 'zone_id', 'zone' => ['id', 'code', 'name'], 'is_active']],
        ]);

    $zone = Zone::query()->where('code', 'ZON-01')->sole();

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/logistics/locations?zone_id={$zone->id}&per_page=1")
        ->assertOk()
        ->assertJsonPath('meta.total', 84);

    // per_page se acota al máximo del contexto (200).
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/logistics/locations?per_page=1000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 200);
});

it('mueve un distrito a otra zona', function () {
    $this->seed(UbigeoSeeder::class);

    $location = Location::query()->where('ubigeo', '150101')->sole();
    $target = Zone::query()->where('code', 'ZON-07')->sole();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/logistics/locations/{$location->id}", [
            'ubigeo' => $location->ubigeo,
            'department' => $location->department,
            'province' => $location->province,
            'district' => $location->district,
            'zone_id' => $target->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.zone_id', $target->id)
        ->assertJsonPath('data.zone.code', 'ZON-07');
});

it('crea coberturas de agencia y rechaza al agente inexistente o el duplicado', function () {
    $this->seed(UbigeoSeeder::class);

    $agency = Agency::factory()->create();
    $location = Location::query()->where('ubigeo', '150101')->sole();

    $payload = [
        'agency_id' => $agency->id,
        'location_id' => $location->id,
        'allows_pickup' => true,
        'allows_delivery' => true,
        'delivery_surcharge' => 7.5,
    ];

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/agency-coverages', $payload)
        ->assertStatus(201)
        ->assertJsonPath('data.delivery_surcharge', '7.50')
        ->assertJsonPath('data.location.ubigeo', '150101');

    // La misma agencia no puede cubrir dos veces la misma ubicación.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/agency-coverages', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('location_id');

    // `agency_id` es de Organization: se valida por el puerto, sin FK.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/logistics/agency-coverages', [...$payload, 'agency_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('agency_id');

    $coverage = AgencyCoverage::query()->sole();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/logistics/agency-coverages/{$coverage->id}", [
            ...$payload,
            'allows_delivery' => false,
            'delivery_surcharge' => 9,
        ])
        ->assertOk()
        ->assertJsonPath('data.allows_delivery', false)
        ->assertJsonPath('data.delivery_surcharge', '9.00');
});

it('exige locations.manage para zonas y ubicaciones', function () {
    $user = adminUser();
    $user->removeRole('admin')->assignRole('counter');

    $this->withHeaders(tokenHeadersFor($user))
        ->getJson('/api/v1/logistics/zones')
        ->assertStatus(403);
});
