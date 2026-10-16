<?php

use Illuminate\Support\Facades\Redis;

it('expone el liveness en /up sin comprobar dependencias', function () {
    $this->get('/up')->assertOk();
});

it('reporta ok cuando todas las dependencias responden', function () {
    Redis::shouldReceive('connection->ping')->once()->andReturn(true);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.database.status', 'up')
        ->assertJsonPath('data.checks.redis.status', 'up')
        ->assertJsonPath('data.checks.queue.status', 'up');
});

it('devuelve 503 con el detalle cuando una dependencia está caída', function () {
    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException('Connection refused'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.status', 'degraded')
        ->assertJsonPath('data.checks.redis.status', 'down')
        ->assertJsonPath('data.checks.redis.detail', 'Connection refused')
        ->assertJsonPath('data.checks.database.status', 'up');
});
