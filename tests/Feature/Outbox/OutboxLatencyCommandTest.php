<?php

use Illuminate\Support\Facades\DB;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Latencia del outbox (cierre de Fase 3)
|--------------------------------------------------------------------------
*/

function processedEvent(int $seconds): OutboxEvent
{
    $event = OutboxEvent::create([
        'event_type' => 'order.created',
        'payload' => ['order_id' => 1],
        'status' => OutboxEvent::STATUS_PROCESSED,
        'processed_at' => now(),
    ]);

    // `created_at` no es fillable: se fuerza para simular la latencia. SQLite
    // guarda las fechas con precisión de segundos, así que la latencia se mide
    // en segundos completos.
    DB::table('outbox_events')->where('id', $event->id)->update([
        'created_at' => now()->subSeconds($seconds),
    ]);

    return $event->refresh();
}

it('calcula p50/p95/máx de los eventos procesados', function () {
    processedEvent(1);
    processedEvent(2);
    processedEvent(3);

    $this->artisan('outbox:latency', ['--minutes' => 15])
        ->expectsOutputToContain('p95 (s)')
        ->expectsOutputToContain('3')
        ->assertSuccessful();
});

it('informa cuando no hay eventos en la ventana', function () {
    $this->artisan('outbox:latency', ['--minutes' => 1])
        ->expectsOutputToContain('No hay eventos procesados')
        ->assertSuccessful();
});
