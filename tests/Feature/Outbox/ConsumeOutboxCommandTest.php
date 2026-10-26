<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Src\Logistics\Order\Jobs\GenerateOrderGuidePdfJob;
use Src\Logistics\Order\Jobs\SendOrderConfirmationMailJob;
use Src\Operations\Shipment\Jobs\CancelShipmentJob;
use Src\Operations\Shipment\Jobs\ProjectOrderCreatedJob;
use Src\Shared\Jobs\AcknowledgeEventJob;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Consumidor del outbox (`outbox:consume`)
|--------------------------------------------------------------------------
|
| La suite es hermética: los jobs se interceptan con `Queue::fake()` y los
| fallos de publicación se simulan con un bus que lanza, así que no hace falta
| Redis ni MySQL.
|
*/

function pendingOutboxEvent(array $attributes = []): OutboxEvent
{
    return OutboxEvent::create(array_merge([
        'event_type' => 'order.created',
        'payload' => ['order_id' => 1, 'ticket_code' => 'ORD-1'],
    ], $attributes));
}

it('publica los eventos pendientes y los marca como processed', function () {
    Queue::fake();

    $event = pendingOutboxEvent();

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertPushed(GenerateOrderGuidePdfJob::class, fn ($job) => $job->outboxEventId === $event->id && $job->queue === 'default');
    Queue::assertPushed(SendOrderConfirmationMailJob::class, fn ($job) => $job->queue === 'default');
    Queue::assertPushed(ProjectOrderCreatedJob::class, fn ($job) => $job->queue === 'sync');

    $event->refresh();

    expect($event->status)->toBe(OutboxEvent::STATUS_PROCESSED)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->error)->toBeNull();
});

it('reintenta con backoff cuando no puede publicar', function () {
    $event = pendingOutboxEvent();

    $this->mock(Dispatcher::class, function (MockInterface $bus) {
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('Redis caído'));
    });

    $this->artisan('outbox:consume')->assertSuccessful();

    $event->refresh();

    expect($event->status)->toBe(OutboxEvent::STATUS_PENDING)
        ->and($event->attempts)->toBe(1)
        ->and($event->error)->toContain('Redis')
        ->and($event->available_at->isFuture())->toBeTrue();
});

it('marca failed cuando se agotan los intentos', function () {
    config()->set('outbox.max_attempts', 2);

    $event = pendingOutboxEvent(['attempts' => 1]);

    $this->mock(Dispatcher::class, function (MockInterface $bus) {
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('Redis caído'));
    });

    $this->artisan('outbox:consume')->assertSuccessful();

    $event->refresh();

    expect($event->status)->toBe(OutboxEvent::STATUS_FAILED)
        ->and($event->attempts)->toBe(2)
        ->and($event->available_at)->toBeNull();
});

it('no toca los eventos que aún no tocan', function () {
    Queue::fake();

    $event = pendingOutboxEvent(['available_at' => now()->addMinutes(5)]);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertNothingPushed();

    expect($event->refresh()->status)->toBe(OutboxEvent::STATUS_PENDING);
});

it('marca failed un evento que ningún contexto consume', function () {
    Queue::fake();

    $event = pendingOutboxEvent(['event_type' => 'payment.registered']);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertNothingPushed();

    $event->refresh();

    expect($event->status)->toBe(OutboxEvent::STATUS_FAILED)
        ->and($event->error)->toContain('payment.registered');
});

it('reconoce los eventos de orden que aún no tienen consumidor real', function () {
    Queue::fake();

    $confirmed = pendingOutboxEvent(['event_type' => 'order.confirmed']);
    $received = pendingOutboxEvent(['event_type' => 'shipment.received']);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertPushed(AcknowledgeEventJob::class, 2);

    expect($confirmed->refresh()->status)->toBe(OutboxEvent::STATUS_PROCESSED)
        ->and($received->refresh()->status)->toBe(OutboxEvent::STATUS_PROCESSED);
});

it('publica la cancelación de la orden al consumidor de Operaciones', function () {
    Queue::fake();

    $cancelled = pendingOutboxEvent(['event_type' => 'order.cancelled']);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertPushed(CancelShipmentJob::class);
    expect($cancelled->refresh()->status)->toBe(OutboxEvent::STATUS_PROCESSED);
});

it('recupera los eventos que dejó colgados un consumidor muerto', function () {
    Queue::fake();

    $event = pendingOutboxEvent();

    // El consumidor anterior lo reclamó (`processing`) y murió a mitad.
    DB::table('outbox_events')->where('id', $event->id)->update([
        'status' => OutboxEvent::STATUS_PROCESSING,
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertPushed(GenerateOrderGuidePdfJob::class);

    expect($event->refresh()->status)->toBe(OutboxEvent::STATUS_PROCESSED);
});

it('no reclama un evento que otro consumidor acaba de coger', function () {
    Queue::fake();

    $event = pendingOutboxEvent(['status' => OutboxEvent::STATUS_PROCESSING]);

    $this->artisan('outbox:consume')->assertSuccessful();

    Queue::assertNothingPushed();

    expect($event->refresh()->status)->toBe(OutboxEvent::STATUS_PROCESSING);
});

it('respeta el límite de eventos por pasada', function () {
    Queue::fake();

    pendingOutboxEvent();
    pendingOutboxEvent();
    pendingOutboxEvent();

    $this->artisan('outbox:consume', ['--limit' => 2])->assertSuccessful();

    expect(OutboxEvent::query()->where('status', OutboxEvent::STATUS_PROCESSED)->count())->toBe(2)
        ->and(OutboxEvent::query()->where('status', OutboxEvent::STATUS_PENDING)->count())->toBe(1);
});

it('agenda el consumidor y los gauges de cola en el scheduler', function () {
    // Sub-minuto con `repeatSeconds` (no con un cron de 6 campos): el evento se
    // repite dentro del minuto, que es lo que sostiene el SLO de 5 s. Lo
    // ejecuta el rol `scheduler` con `schedule:work` (ver el aviso de
    // `07-devops/04` sobre el `schedule:run` por cron de DOKS).
    $events = collect(app(Schedule::class)->events());

    $consumer = $events->firstWhere(fn ($event) => str_contains($event->command ?? '', 'outbox:consume'));
    $gauges = $events->firstWhere(fn ($event) => str_contains($event->command ?? '', 'metrics:collect'));

    expect($consumer)->not->toBeNull()
        ->and($consumer->expression)->toBe('* * * * *')
        ->and($consumer->repeatSeconds)->toBe(1)
        ->and($gauges->repeatSeconds)->toBe(15);
});
