<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
| El login web (cookie) solo arranca sesión si la petición viene del frontend:
| Sanctum compara el `Origin`/`Referer` con `SANCTUM_STATEFUL_DOMAINS`, que en
| `phpunit.xml` vale `localhost`. Las apps/PDA no necesitan esto: mandan bearer.
*/
function statefulOrigin(): array
{
    return ['Origin' => 'http://localhost'];
}

it('inicia sesión con credenciales válidas y devuelve el perfil con permisos', function () {
    $user = adminUser();

    $response = $this->withHeaders(statefulOrigin())->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.roles', ['admin'])
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'name', 'email'],
                'roles',
                'permissions',
                'agencies',
            ],
        ]);

    expect($response->json('data.permissions'))->toContain('orders.create');
});

it('rechaza credenciales incorrectas con un mensaje neutro', function () {
    $user = adminUser();

    $this->withHeaders(statefulOrigin())->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'incorrecta',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('devuelve el perfil del usuario autenticado por sesión', function () {
    $user = adminUser();

    $this->actingAs($user)
        ->withHeaders(statefulOrigin())
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.roles', ['admin']);
});

it('emite un token opaco para apps y lo acepta como bearer', function () {
    $user = adminUser();

    $token = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
        'platform' => 'android',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_in', 43200 * 60)
        ->json('data.token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);

    // El dispositivo se guarda con plataforma para poder revocar por nombre.
    expect($user->tokens()->pluck('name')->all())->toBe(['Pixel 8 (android)']);
});

it('no emite token con credenciales incorrectas', function () {
    $user = adminUser();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'incorrecta',
        'device_name' => 'PDA',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect($user->tokens()->count())->toBe(0);
});

it('exige device_name para emitir un token', function () {
    $user = adminUser();

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('device_name');
});

it('revoca el token actual en logout', function () {
    $user = adminUser();
    $token = $user->createToken('pda')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('revoca todos los dispositivos con logout-all', function () {
    $user = adminUser();
    $token = $user->createToken('pda')->plainTextToken;
    $user->createToken('tablet');

    $this->withToken($token)->postJson('/api/v1/auth/logout-all')->assertOk();

    expect($user->tokens()->count())->toBe(0);

    // El guard `sanctum` es un `RequestGuard`: cachea el usuario entre peticiones
    // del mismo test, así que hay que olvidar los guards para volver a resolver.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('exige autenticación para el perfil', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('limita los intentos de login por email+IP', function () {
    $payload = ['email' => 'nadie@example.com', 'password' => 'mala'];

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(422);
    }

    // Criterio 11: pasado el límite de intentos por minuto responde 429.
    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
});

it('limita los intentos de login por IP aunque cambie el email', function () {
    // Variando el email no se activa el límite por email+IP (5/min), así que la
    // petición 11 desde la misma IP es la que topa con los 10/min.
    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => "nadie{$attempt}@example.com",
            'password' => 'mala',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nadie11@example.com',
        'password' => 'mala',
    ])->assertStatus(429);
});

it('envía el enlace de recuperación cuando el email existe', function () {
    Notification::fake();

    $user = adminUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo(
        $user,
        ResetPassword::class,
        function (ResetPassword $notification, array $channels) use ($user): bool {
            // El enlace apunta a la SPA, bajo `/auth/reset-password`, como el
            // resto de las pantallas de sesión (`createUrlUsing` en
            // AppServiceProvider). Ver criterio 1 de la Fase 1 y ADR-0008:
            // si cambia la URL hay que regenerar el contrato.
            $mail = $notification->toMail($user);

            expect($mail->actionUrl)
                ->toContain('/auth/reset-password?token=')
                ->toContain('email='.urlencode($user->email));

            return true;
        },
    );
});

it('responde igual cuando el email no existe, sin enviar nada', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'fantasma@example.com'])
        ->assertOk();

    Notification::assertNothingSent();
});

it('restablece la contraseña con el token del correo y revoca los tokens', function () {
    $user = adminUser();
    $token = $user->createToken('pda')->plainTextToken;
    $resetToken = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken,
        'email' => $user->email,
        'password' => 'nueva-clave-2026',
        'password_confirmation' => 'nueva-clave-2026',
    ])->assertOk();

    expect(Hash::check('nueva-clave-2026', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rechaza un token de recuperación inválido', function () {
    $user = adminUser();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'invalido',
        'email' => $user->email,
        'password' => 'nueva-clave-2026',
        'password_confirmation' => 'nueva-clave-2026',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('ya no expone el registro público: el alta de usuarios es de Identity', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(404);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});
