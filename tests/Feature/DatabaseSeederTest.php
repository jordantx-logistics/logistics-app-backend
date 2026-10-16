<?php

use App\Models\User;

it('se puede ejecutar dos veces sin romper', function () {
    $this->seed();
    $this->seed();

    $user = User::where('email', env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe'))->sole();

    expect($user->name)->toBe('Admin')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('deja un admin que puede autenticarse con las credenciales del entorno', function () {
    $this->seed();

    $token = auth('api')->attempt([
        'email' => env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe'),
        'password' => env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#'),
    ]);

    expect($token)->not->toBeFalse();
});
