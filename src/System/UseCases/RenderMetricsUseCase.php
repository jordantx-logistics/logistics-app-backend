<?php

namespace Src\System\UseCases;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Src\System\Services\Metrics\DatabaseConnectionsGauge;
use Src\System\Services\Metrics\MetricsRecorder;

/**
 * Prepara y renderiza el scrape de Prometheus.
 */
class RenderMetricsUseCase
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly MetricsRecorder $recorder,
        private readonly DatabaseConnectionsGauge $connections,
    ) {}

    /**
     * @return array{body: string, content_type: string}
     */
    public function execute(): array
    {
        // Los gauges que no se pueden mantener por petición se refrescan aquí,
        // en el momento del scrape.
        if (($used = $this->connections->value()) !== null) {
            $this->recorder->recordDatabaseConnections($used);
        }

        return [
            'body' => (new RenderTextFormat)->render($this->registry->getMetricFamilySamples()),
            'content_type' => RenderTextFormat::MIME_TYPE,
        ];
    }
}
