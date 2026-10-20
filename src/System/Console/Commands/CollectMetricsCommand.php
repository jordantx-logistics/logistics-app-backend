<?php

namespace Src\System\Console\Commands;

use Illuminate\Console\Command;
use Src\System\Services\Metrics\MetricsRecorder;
use Src\System\Services\Metrics\OutboxBacklogGauge;
use Src\System\Services\Metrics\QueueBacklogGauge;
use Src\System\Services\Metrics\QueueFailedCounter;

/**
 * Gauges que no se pueden calcular en el scrape
 * (`spec/07-devops/07-observabilidad.md`: "se calculan por un comando programado
 * que consulta Redis/MySQL y actualiza un exporter").
 *
 * Lo ejecuta el rol `scheduler` cada pocos segundos: el tamaño de cada cola
 * (Redis), los fallos acumulados (MySQL) y la antigüedad del evento pendiente
 * más viejo (MySQL). El almacén de métricas es Redis, así que el valor escrito
 * aquí es el mismo que ve cualquier worker de Octane al servir `/metrics`.
 */
class CollectMetricsCommand extends Command
{
    protected $signature = 'metrics:collect';

    protected $description = 'Calcula los gauges de colas y outbox del exporter de Prometheus.';

    public function handle(
        MetricsRecorder $recorder,
        QueueBacklogGauge $backlog,
        QueueFailedCounter $failures,
        OutboxBacklogGauge $outbox,
    ): int {
        foreach ((array) config('outbox.queues') as $queue) {
            if (($size = $backlog->value($queue)) !== null) {
                $recorder->recordQueueBacklog($queue, $size);
            }

            $recorder->recordQueueFailures($queue, $failures->newFailures($queue));
        }

        $sample = $outbox->sample();

        $recorder->recordOutboxBacklog($sample['pending'], $sample['oldest_seconds']);

        return self::SUCCESS;
    }
}
