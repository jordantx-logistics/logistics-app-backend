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
}
