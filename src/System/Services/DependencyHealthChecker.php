<?php

namespace Src\System\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Comprueba las dependencias externas del servicio (BD, Redis y colas).
 *
 * Cada sonda se mide por separado y nunca lanza: un fallo se reporta como
 * `down` para que el endpoint de readiness pueda devolver 503 con el detalle.
 */
class DependencyHealthChecker
{
    /**
     * @return array<string, array{status: string, detail: string, latency_ms: float}>
     */
    public function check(): array
    {
        return [
            'database' => $this->probe(fn () => $this->probeDatabase()),
            'redis' => $this->probe(fn () => $this->probeRedis()),
            'queue' => $this->probe(fn () => $this->probeQueue()),
        ];
    }

    private function probeDatabase(): string
    {
        DB::select('select 1');

        return 'conexión "'.config('database.default').'" responde';
    }

    private function probeRedis(): string
    {
        Redis::connection()->ping();

        return 'PONG de "'.config('database.redis.default.host').'"';
    }

    private function probeQueue(): string
    {
        $connection = (string) config('queue.default');

        // Sin dependencia externa: no hay nada que sondear.
        if (in_array($connection, ['sync', 'null'], true)) {
            return "driver \"{$connection}\" (sin dependencia externa)";
        }

        $queue = (string) config("queue.connections.{$connection}.queue", 'default');
        $size = Queue::connection($connection)->size($queue);

        return "{$connection}:{$queue} ({$size} pendientes)";
    }

    /**
     * @param  callable(): string  $call
     * @return array{status: string, detail: string, latency_ms: float}
     */
    private function probe(callable $call): array
    {
        $startedAt = hrtime(true);

        try {
            $detail = $call();
            $status = 'up';
        } catch (Throwable $e) {
            $detail = $e->getMessage();
            $status = 'down';
        }

        return [
            'status' => $status,
            'detail' => $detail,
            'latency_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        ];
    }
}
