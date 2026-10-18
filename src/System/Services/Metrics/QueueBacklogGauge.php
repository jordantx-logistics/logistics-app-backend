<?php

namespace Src\System\Services\Metrics;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Tamaño de cada cola (`queue_backlog`).
 *
 * Se pregunta al driver de colas (`QueueFactory::connection()->size()`) en vez
 * de leer Redis a mano: el nombre real de la clave (`queues:high`, con el
 * prefijo del proyecto) es cosa del driver. Si no se puede leer —un driver sin
 * `size()` o Redis caído— devuelve `null` y el scrape omite la serie: un gauge
 * que falta no puede tumbar el exporter.
 */
final class QueueBacklogGauge
{
    public function __construct(
        private readonly QueueFactory $queues,
    ) {}

    public function value(string $queue): ?float
    {
        try {
            return (float) $this->queues->connection()->size($queue);
        } catch (Throwable) {
            return null;
        }
    }
}
