<?php

namespace Src\System\Services\Metrics;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * `queue_failed_total`: jobs fallidos por cola.
 *
 * Prometheus espera un contador (monótono), no el total de `failed_jobs`: cada
 * pasada cuenta solo las filas nuevas desde el último id visto y suma eso. El
 * último id vive en la caché (Redis), así que reiniciar el contenedor no vuelve
 * a contar lo ya contado. Si la caché se vacía, la primera pasada cuenta los
 * fallos existentes de golpe —un pico, nunca un contador que baja.
 */
final class QueueFailedCounter
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function newFailures(string $queue): int
    {
        $key = "metrics.failed_jobs.last_id.{$queue}";
        $lastId = (int) $this->cache->get($key, 0);

        $new = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->where('id', '>', $lastId)
            ->count();

        $maxId = (int) DB::table('failed_jobs')->where('queue', $queue)->max('id');

        if ($maxId > $lastId) {
            $this->cache->forever($key, $maxId);
        }

        return $new;
    }
}
