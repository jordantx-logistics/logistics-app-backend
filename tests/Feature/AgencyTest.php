<?php

use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
});

it('can list agencies', function () {
    Agency::factory()->count(3)->create();

    $response = $this->withHeaders($this->headers)
        ->getJson('/api/v1/organization/agencies');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'code', 'name', 'address', 'phone', 'district', 'is_active'],
            ],
        ]);
});

it('can create an agency', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/organization/agencies', [
            'code' => 'LIM01',
            'name' => 'Lima Central',
            'address' => 'Av. Javier Prado 123',
            'district' => 'San Isidro',
            'is_active' => true,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.code', 'LIM01')
        ->assertJsonPath('data.name', 'Lima Central');

    $this->assertDatabaseHas('agencies', [
        'code' => 'LIM01',
        'name' => 'Lima Central',
    ]);
});

it('validates unique code when creating agency', function () {
    Agency::factory()->create(['code' => 'LIM01']);

    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/organization/agencies', [
            'code' => 'LIM01', // Duplicate
            'name' => 'Lima Central',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code'], 'data');
});

/*
|--------------------------------------------------------------------------
| Ámbito por agencia (criterio 5)
|--------------------------------------------------------------------------
|
| Un rol nacional (`admin`, `analyst`) opera sin filtro; el resto queda acotado
| a `agency_user`. Es el aislamiento A/B de la Fase 1.
|
*/

it('acota el listado a las agencias del usuario', function () {
    $agenciaA = Agency::factory()->create(['name' => 'Agencia A']);
    Agency::factory()->create(['name' => 'Agencia B']);

    $supervisor = adminUser();
    $supervisor->removeRole('admin');
    $supervisor->assignRole('agency-supervisor');
    $supervisor->agencies()->attach($agenciaA);

    $this->actingAs($supervisor)
        ->getJson('/api/v1/organization/agencies')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $agenciaA->id);
});

it('deja que un rol nacional vea todas las agencias', function () {
    Agency::factory()->count(3)->create();

    $this->actingAs(adminUser())
        ->getJson('/api/v1/organization/agencies')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('no deja ver nada a un usuario operativo sin agencia asignada', function () {
    Agency::factory()->count(2)->create();

    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('agency-supervisor');

    $this->actingAs($user)
        ->getJson('/api/v1/organization/agencies')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('responde 403 sin el permiso exigido por la ruta', function () {
    Agency::factory()->count(2)->create();

    $user = adminUser();
    $user->removeRole('admin');

    $this->actingAs($user)
        ->getJson('/api/v1/organization/agencies')
        ->assertForbidden();
});
