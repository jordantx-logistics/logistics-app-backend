<?php

use App\Models\User;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = auth('api')->login($this->user);
    $this->headers = [
        'Authorization' => "Bearer {$this->token}",
    ];
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
