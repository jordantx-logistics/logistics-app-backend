<?php

namespace Src\Shared\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Decide si las lecturas pueden ir a la réplica.
 *
 * Por defecto Laravel lee de la réplica hasta la primera escritura de la petición,
 * y eso no basta: un caso de uso valida antes de escribir, y lo haría contra datos
 * con retraso. Aquí se fija el primary de antemano. El `sticky` de la conexión es
 * el mecanismo, y `recordsHaveBeenModified` su interruptor.
 */
class ReadWriteRouting
{
    public function __construct(private readonly Cache $cache) {}

    public function enabled(): bool
    {
        return config('database.connections.'.config('database.default').'.read') !== null;
    }

    public function pinToPrimary(): void
    {
        if ($this->enabled()) {
            $this->connection()->recordsHaveBeenModified(true);
        }
    }

    public function allowReplica(): void
    {
        if ($this->enabled()) {
            $this->connection()->forgetRecordModificationState();
        }
    }

    /**
     * Quien acaba de escribir lee del primary unos segundos, también en peticiones
     * siguientes: crear una orden y abrir su detalle no debe dar 404 por el lag.
     */
    public function rememberWrite(int|string $userId): void
    {
        $ttl = (int) config('database.read_your_writes_seconds');

        if ($this->enabled() && $ttl > 0) {
            $this->cache->put($this->key($userId), true, $ttl);
        }
    }

    public function pinIfWroteRecently(int|string|null $userId): void
    {
        if ($userId !== null && $this->enabled() && $this->cache->has($this->key($userId))) {
            $this->pinToPrimary();
        }
    }

    private function key(int|string $userId): string
    {
        return 'db-primary-pin:'.$userId;
    }

    private function connection(): Connection
    {
        return DB::connection();
    }
}
