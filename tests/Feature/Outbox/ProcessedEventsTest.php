<?php

use Src\Shared\Models\ProcessedEvent;
use Src\Shared\Services\ProcessedEvents;

/*
|--------------------------------------------------------------------------
| Idempotencia de los jobs del outbox
|--------------------------------------------------------------------------
*/

it('aplica el efecto una sola vez por evento y handler', function () {
    $processed = app(ProcessedEvents::class);
    $calls = 0;

    $effect = function () use (&$calls) {
        $calls++;
    };

    $processed->once(7, 'JobA', $effect);
    $processed->once(7, 'JobA', $effect);

    expect($calls)->toBe(1)
        ->and(ProcessedEvent::query()->count())->toBe(1);
});

it('deja pasar a otro handler del mismo evento', function () {
    $processed = app(ProcessedEvents::class);

    $processed->once(7, 'JobA', fn () => null);
    $processed->once(7, 'JobB', fn () => null);

    expect(ProcessedEvent::query()->count())->toBe(2);
});

it('no marca el evento si el efecto falla, así el reintento lo vuelve a intentar', function () {
    $processed = app(ProcessedEvents::class);
    $calls = 0;

    $effect = function () use (&$calls) {
        $calls++;

        throw new RuntimeException('boom');
    };

    expect(fn () => $processed->once(7, 'JobA', $effect))->toThrow(RuntimeException::class);

    // La marca se confirmó en la misma transacción que el efecto: al fallar
    // aquel, no queda rastro y el job reintentado vuelve a entrar.
    expect(ProcessedEvent::query()->count())->toBe(0);

    $processed->once(7, 'JobA', fn () => null);

    expect($calls)->toBe(1)
        ->and(ProcessedEvent::query()->count())->toBe(1);
});
