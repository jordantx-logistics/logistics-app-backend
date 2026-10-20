<?php

use App\Models\User;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
    $this->agency = Agency::factory()->create();
});

function userPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Quispe',
        'email' => 'ana@empresa.com',
        'password' => 'Secreta12345',
    ], $overrides);
}

it('da de alta un usuario con el rol por defecto y su ámbito de agencia', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/users', userPayload([
            'agency_ids' => [$this->agency->id],
        ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.email', 'ana@empresa.com')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.roles', ['employee'])
        ->assertJsonPath('data.agencies.0.id', $this->agency->id)
        ->assertJsonPath('data.agencies.0.is_primary', true);

    $created = User::where('email', 'ana@empresa.com')->firstOrFail();

    // La contraseña se guarda hasheada, nunca en claro.
    expect($created->password)->not->toBe('Secreta12345');
});

it('rechaza un email duplicado y una contraseña débil', function () {
    User::factory()->create(['email' => 'ana@empresa.com']);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/users', userPayload(['agency_ids' => [$this->agency->id]]))
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'USER_EMAIL_TAKEN');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/users', userPayload([
            'email' => 'otra@empresa.com',
            'password' => 'corta',
            'agency_ids' => [$this->agency->id],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'PASSWORD_TOO_WEAK');
});

it('exige agencia al alta de un rol operativo', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/users', userPayload())
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'USER_WITHOUT_AGENCY_SCOPE');
});

it('lista usuarios con filtros por estado y agencia', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/users', userPayload(['agency_ids' => [$this->agency->id]]))
        ->assertStatus(201);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/users?status=active&agency_id='.$this->agency->id)
        ->assertOk()
        ->assertJsonPath('data.0.email', 'ana@empresa.com');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/users?status=inactive')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('actualiza los datos de un usuario', function () {
    $user = User::factory()->create();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/identity/users/{$user->id}", ['name' => 'Nombre Nuevo'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nombre Nuevo');

    expect($user->fresh()->name)->toBe('Nombre Nuevo');
});

it('suspende a un usuario y revoca sus tokens', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('counter');
    $user->createToken('pda');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/identity/users/{$user->id}")
        ->assertOk();

    expect($user->fresh()->status)->toBe(User::STATUS_SUSPENDED)
        ->and($user->tokens()->count())->toBe(0);
});

it('no deja suspender al último admin activo', function () {
    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/identity/users/{$this->user->id}")
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'LAST_ADMIN_PROTECTED');
});

it('reactiva a un usuario suspendido y bloquea el paso desde inactive', function () {
    $user = User::factory()->create();
    $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/identity/users/{$user->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

    $this->withHeaders($this->headers)
        ->patchJson("/api/v1/identity/users/{$user->id}/status", ['status' => 'active'])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'INVALID_STATUS_TRANSITION');
});

it('desbloquea una cuenta bloqueada por intentos fallidos', function () {
    $user = User::factory()->create();
    $user->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save();

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/identity/users/{$user->id}/unlock")
        ->assertOk();

    expect($user->fresh()->failed_login_attempts)->toBe(0)
        ->and($user->fresh()->locked_until)->toBeNull();
});

it('sincroniza el ámbito de agencia y su agencia principal', function () {
    $otra = Agency::factory()->create();
    $user = User::factory()->create();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/identity/users/{$user->id}/agency-scope", [
            'agency_ids' => [$this->agency->id, $otra->id],
            'primary_agency_id' => $otra->id,
        ])
        ->assertOk();

    $agencies = $user->fresh()->agencies;

    expect($agencies)->toHaveCount(2)
        ->and($agencies->firstWhere('id', $otra->id)->pivot->is_primary)->toBeTruthy()
        ->and($agencies->firstWhere('id', $this->agency->id)->pivot->is_primary)->toBeFalsy();
});

it('responde 403 sin users.manage y 401 sin sesión', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('counter');

    $this->actingAs($user)->getJson('/api/v1/identity/users')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/identity/users')->assertUnauthorized();
});
