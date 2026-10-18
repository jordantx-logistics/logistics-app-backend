<?php

namespace Src\System\Services\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;

/**
 * Métricas que expone la aplicación (tabla de `07-devops/07-observabilidad.md`).
 *
 * Los colectores se registran una vez por proceso: el almacén (Redis) es lo que
 * hace que el contador sea común a todos los workers de Octane.
 */
final class MetricsRecorder
{
    private readonly Counter $requests;

    private readonly Histogram $duration;

    private readonly Gauge $inFlight;

    private readonly Gauge $databaseConnections;

    private readonly Gauge $queueBacklog;

    private readonly Counter $queueFailures;

    private readonly Gauge $outboxPending;

    private readonly Gauge $outboxPendingAge;

    /**
     * @param  float[]  $buckets  cortes del histograma de latencia, en segundos
     */
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $environment,
        array $buckets,
    ) {
        $this->requests = $registry->getOrRegisterCounter(
            '',
            'http_requests_total',
            'Peticiones HTTP por ruta, método y estado.',
            ['route', 'method', 'status', 'env'],
        );

        $this->duration = $registry->getOrRegisterHistogram(
            '',
            'http_request_duration_seconds',
            'Duración de la petición HTTP en segundos.',
            ['route', 'env'],
            $buckets,
        );

        /*
         * Aproximación de workers ocupados: peticiones en curso. Si un worker se
         * recicla (`max_requests`) o muere a mitad de petición, el `dec` no
         * llega y el gauge queda alto hasta que se reinicie el exporter; sirve
         * como tendencia, no como medida exacta.
         */
        $this->inFlight = $registry->getOrRegisterGauge(
            '',
            'octane_workers_busy',
            'Peticiones en curso (workers de Octane ocupados).',
            ['env'],
        );

        $this->databaseConnections = $registry->getOrRegisterGauge(
            '',
            'db_connections_used',
            'Conexiones abiertas en el servidor de base de datos.',
            ['pool', 'env'],
        );

        /*
         * Las tres siguientes las alimenta `metrics:collect` desde el rol
         * `scheduler`: leerlas por petición costaría una consulta por scrape en
         * cada worker de Octane.
         */
        $this->queueBacklog = $registry->getOrRegisterGauge(
            '',
            'queue_backlog',
            'Jobs esperando en cada cola.',
            ['queue', 'env'],
        );

        $this->queueFailures = $registry->getOrRegisterCounter(
            '',
            'queue_failed_total',
            'Jobs fallidos por cola (acumulado, nunca decrece).',
            ['queue', 'env'],
        );

        $this->outboxPending = $registry->getOrRegisterGauge(
            '',
            'outbox_pending_total',
            'Eventos del outbox pendientes de publicar.',
            ['env'],
        );

        $this->outboxPendingAge = $registry->getOrRegisterGauge(
            '',
            'outbox_pending_age_seconds',
            'Antigüedad del evento pendiente más viejo, en segundos.',
            ['env'],
        );
    }

    public function requestStarted(): void
    {
        $this->inFlight->inc([$this->environment]);
    }

    public function requestFinished(): void
    {
        $this->inFlight->dec([$this->environment]);
    }

    public function recordRequest(string $method, string $route, int $status, float $durationSeconds): void
    {
        $this->requests->inc([$route, $method, (string) $status, $this->environment]);
        $this->duration->observe($durationSeconds, [$route, $this->environment]);
    }

    /**
     * El valor se lee en cada scrape (`Threads_connected` del servidor MySQL),
     * no en cada petición: es un gauge y Prometheus lo refresca cada intervalo.
     */
    public function recordDatabaseConnections(float $used, string $pool = 'default'): void
    {
        $this->databaseConnections->set($used, [$pool, $this->environment]);
    }

    public function recordQueueBacklog(string $queue, float $size): void
    {
        $this->queueBacklog->set($size, [$queue, $this->environment]);
    }

    /**
     * Suma solo los fallos nuevos: el contador es monótono y `rate()` debe dar
     * la tasa real, no el acumulado de la tabla.
     */
    public function recordQueueFailures(string $queue, int $newFailures): void
    {
        if ($newFailures > 0) {
            $this->queueFailures->incBy($newFailures, [$queue, $this->environment]);
        }
    }

    public function recordOutboxBacklog(int $pending, int $oldestSeconds): void
    {
        $this->outboxPending->set($pending, [$this->environment]);
        $this->outboxPendingAge->set($oldestSeconds, [$this->environment]);
    }
}
