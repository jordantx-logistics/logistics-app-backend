<?php

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Src\Shared\Models\OutboxEvent;

/*
|--------------------------------------------------------------------------
| Gauges de colas y outbox (`metrics:collect`)
|--------------------------------------------------------------------------
|
| El almacén de métricas en tests es `in-memory`, así que lo que escribe el
| comando se lee luego en el mismo proceso al pedir `/metrics`.
|
*/

/**
 * El driver de colas en tests es `sync` (`phpunit.xml`): suelta un 0 por cola
 * sin tocar Redis.
 */
it('publica el backlog de cada cola', function () {
    $connection = Mockery::mock(QueueContract::class);
    $connection->shouldReceive('size')->with('high')->andReturn(7);
    $connection->shouldReceive('size')->with('default')->andReturn(0);
    $connection->shouldReceive('size')->with('reports')->andReturn(0);
    $connection->shouldReceive('size')->with('sync')->andReturn(0);

    $this->instance(QueueFactory::class, Mockery::mock(QueueFactory::class, function (MockInterface $factory) use ($connection) {
        $factory->shouldReceive('connection')->andReturn($connection);
    }));

    $this->artisan('metrics:collect')->assertSuccessful();

    expect($this->get('/metrics')->getContent())
        ->toContain('queue_backlog{queue="high",env="testing"} 7');
});

it('no rompe el scrape si no puede leer la cola', function () {
    $this->instance(QueueFactory::class, Mockery::mock(QueueFactory::class, function (MockInterface $factory) {
        $factory->shouldReceive('connection')->andThrow(new RuntimeException('Redis caído'));
    }));

    $this->artisan('metrics:collect')->assertSuccessful();

    expect($this->get('/metrics')->getContent())->not->toContain('queue_backlog{');
});

it('cuenta los fallos nuevos una sola vez', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $this->artisan('metrics:collect')->assertSuccessful();
    $this->artisan('metrics:collect')->assertSuccessful();

    expect($this->get('/metrics')->getContent())
        ->toContain('queue_failed_total{queue="default",env="testing"} 1');
});

it('publica el backlog y la antigüedad del evento pendiente más viejo', function () {
    OutboxEvent::create([
        'event_type' => 'order.created',
        'payload' => ['order_id' => 1],
    ]);

    DB::table('outbox_events')->update(['created_at' => now()->subMinutes(2)]);

    $this->artisan('metrics:collect')->assertSuccessful();

    $body = $this->get('/metrics')->getContent();

    expect($body)
        ->toContain('outbox_pending_total{env="testing"} 1')
        ->toMatch('/outbox_pending_age_seconds\{env="testing"\} 12[0-9]/');
});
