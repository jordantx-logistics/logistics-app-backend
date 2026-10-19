<?php

use Src\Identity\Device\Models\Device;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
});

function deviceTokenPayload(string $email, array $overrides = []): array
{
    return array_merge([
        'email' => $email,
        'password' => 'password',
        'device_name' => 'PDA A1',
        'platform' => 'pda',
    ], $overrides);
}

it('registra el dispositivo al emitir un token con device_uuid', function () {
    $response = $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, [
        'device_uuid' => 'uuid-pda-1',
        'app_version' => '2.1.0',
    ]));

    $response->assertOk();

    $this->assertDatabaseHas('devices', [
        'user_id' => $this->user->id,
        'device_uuid' => 'uuid-pda-1',
        'platform' => 'pda',
        'status' => Device::STATUS_ACTIVE,
        'app_version' => '2.1.0',
    ]);
});

it('no registra dispositivo si el token no trae device_uuid', function () {
    $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email))->assertOk();

    expect(Device::query()->count())->toBe(0);
});

it('reutiliza la fila del mismo dispositivo', function () {
    $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, ['device_uuid' => 'uuid-pda-1']))->assertOk();
    $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, ['device_uuid' => 'uuid-pda-1']))->assertOk();

    expect(Device::query()->where('device_uuid', 'uuid-pda-1')->count())->toBe(1);
});

it('rechaza el alta al superar el límite de dispositivos activos', function () {
    config(['identity.device_limit' => 1]);

    $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, ['device_uuid' => 'uuid-a']))->assertOk();

    $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, ['device_uuid' => 'uuid-b']))
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'DEVICE_LIMIT_REACHED');
});

it('exige la plataforma cuando se registra el dispositivo', function () {
    $this->postJson('/api/v1/auth/token', [
        'email' => $this->user->email,
        'password' => 'password',
        'device_name' => 'PDA A1',
        'device_uuid' => 'uuid-pda-1',
    ])->assertStatus(422)->assertJsonValidationErrors('platform');
});

it('lista los dispositivos del usuario autenticado', function () {
    Device::factory()->count(2)->create(['user_id' => $this->user->id]);
    Device::factory()->create();

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'device_name', 'platform', 'device_uuid', 'status', 'last_seen_at'],
            ],
        ]);
});

it('revoca el dispositivo y borra su token', function () {
    $token = $this->postJson('/api/v1/auth/token', deviceTokenPayload($this->user->email, [
        'device_uuid' => 'uuid-pda-1',
    ]))->json('data.token');

    // El token vive: la petición con bearer se autentica.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->app['auth']->forgetGuards();

    $device = Device::query()->where('device_uuid', 'uuid-pda-1')->firstOrFail();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/identity/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    // Solo se borra el token del dispositivo; el token de la sesión de test sigue.
    expect($device->fresh()->status)->toBe(Device::STATUS_REVOKED)
        ->and($device->fresh()->revoked_at)->not->toBeNull()
        ->and($this->user->tokens()->where('name', 'PDA A1 (pda)')->count())->toBe(0)
        ->and($this->user->tokens()->where('name', 'test')->count())->toBe(1);

    // El token revocado ya no autentica.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('responde 403 sin devices.manage', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('cashier');

    $this->actingAs($user)
        ->getJson('/api/v1/identity/devices')
        ->assertForbidden();
});

it('exige sesión para listar dispositivos', function () {
    $this->getJson('/api/v1/identity/devices')->assertUnauthorized();
});
