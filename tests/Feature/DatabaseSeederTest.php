<?php

use App\Models\User;

it('se puede ejecutar dos veces sin romper', function () {
    $this->seed();
    $this->seed();

    $user = User::where('email', env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe'))->sole();

    expect($user->name)->toBe('Admin')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('deja un admin con el rol del catálogo y credenciales válidas', function () {
    $this->seed();

    $user = User::where('email', env('SCRIBE_AUTH_EMAIL', 'admin@admin.pe'))->sole();

    // Sin rol, el middleware de permisos dejaría al admin fuera de todas las rutas.
    expect($user->hasRole('admin'))->toBeTrue()
        ->and($user->hasPermissionTo('orders.create'))->toBeTrue();

    expect(auth('web')->validate([
        'email' => $user->email,
        'password' => env('SCRIBE_AUTH_PASSWORD', 'SecureAdmin2026!@#'),
    ]))->toBeTrue();
});
