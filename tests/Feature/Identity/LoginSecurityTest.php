<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Endurecimiento del login (Fase 2, paso 5)
|--------------------------------------------------------------------------
|
| La verificación de credenciales (estado, bloqueo, intentos fallidos) es común
| al login web y a la emisión de token: vive en `AuthenticateUserService`.
|
*/

it('no deja iniciar sesión a un usuario suspendido', function () {
    $user = User::factory()->create();
    $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(403)
        ->assertJsonPath('data.error_code', 'USER_INACTIVE');
});

it('no deja iniciar sesión a una cuenta bloqueada', function () {
    $user = User::factory()->create();
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(423)
        ->assertJsonPath('data.error_code', 'USER_LOCKED');
});

it('bloquea la cuenta al agotar los intentos fallidos y se desbloquea sola', function () {
    config(['identity.max_login_attempts' => 2, 'identity.lock_minutes' => 15]);

    $user = User::factory()->create();

    foreach (range(1, 2) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrecta',
        ])->assertStatus(422);
    }

    expect($user->fresh()->failed_login_attempts)->toBe(2)
        ->and($user->fresh()->locked_until)->not->toBeNull();

    // Con la contraseña correcta sigue bloqueada hasta que expire el bloqueo.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(423);

    // Simulamos el paso del tiempo: el bloqueo expira.
    $user->fresh()->forceFill(['locked_until' => now()->subMinute()])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    expect($user->fresh()->failed_login_attempts)->toBe(0)
        ->and($user->fresh()->locked_until)->toBeNull()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('registra el último acceso en la emisión de token', function () {
    $user = adminUser();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'PDA',
        'platform' => 'pda',
    ])->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});
