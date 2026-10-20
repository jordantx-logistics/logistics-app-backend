<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;

it('configura las colas del spec con balanceo automático', function () {
    $supervisor = config('horizon.defaults.supervisor-1');

    expect($supervisor['queue'])->toBe(['high', 'default', 'reports', 'sync'])
        ->and($supervisor['connection'])->toBe('redis')
        ->and($supervisor['balance'])->toBe('auto');
});

it('reparte las colas por entorno según 07-devops/01-entornos.md', function () {
    // Lo que no se define por entorno se hereda de `defaults`.
    expect(config('horizon.environments.staging.supervisor-1.queue'))->toBe(['high', 'default'])
        ->and(config('horizon.environments.production.supervisor-1.queue'))->toBeNull()
        ->and(config('horizon.environments.local.supervisor-1'))->toHaveKey('maxProcesses');
});

it('programa las instantáneas de Horizon que alimentan sus gráficas', function () {
    // El paquete registra `horizon:snapshot` pero no lo agenda: sin esto las
    // gráficas del panel quedan vacías.
    $events = collect(app(Schedule::class)->events());

    $snapshot = $events->firstWhere(fn ($event) => str_contains($event->command ?? '', 'horizon:snapshot'));

    expect($snapshot)->not->toBeNull();
    expect($snapshot->expression)->toBe('*/5 * * * *');
});

it('deja el panel abierto en local aunque el email no esté autorizado', function () {
    // No es comportamiento nuestro: es el de Horizon (`Horizon::auth`, "local
    // siempre pasa"). Se fija aquí porque el spec depende de él: si una versión
    // futura lo cambiara, queremos enterarnos por un test y no en el panel.
    app()->detectEnvironment(fn () => 'local');

    expect(Horizon::check(Request::create('/horizon')))->toBeTrue();
});

it('cierra el panel fuera de local si el email no está autorizado', function () {
    config(['horizon.allowed_emails' => ['ops@logistica.pe']]);
    app()->detectEnvironment(fn () => 'production');

    // Ojo: en una gate de callback el primer parámetro es siempre el usuario
    // autenticado (`Auth::user()`), no lo que se pasa en el array de argumentos.
    // Por eso aquí se entra con `actingAs` y se resuelve el usuario en la request.
    $user = new User(['email' => 'intruso@logistica.pe']);
    $this->actingAs($user);

    $request = Request::create('/horizon');
    $request->setUserResolver(fn () => $user);

    expect(Horizon::check($request))->toBeFalse();
});

it('abre el panel fuera de local al email autorizado', function () {
    config(['horizon.allowed_emails' => ['ops@logistica.pe']]);
    app()->detectEnvironment(fn () => 'production');

    $user = new User(['email' => 'ops@logistica.pe']);
    $this->actingAs($user);

    $request = Request::create('/horizon');
    $request->setUserResolver(fn () => $user);

    expect(Horizon::check($request))->toBeTrue();
});
