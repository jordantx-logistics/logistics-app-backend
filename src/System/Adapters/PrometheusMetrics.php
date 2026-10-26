<?php

namespace Src\System\Adapters;

use Prometheus\CollectorRegistry;
use Src\Shared\Contracts\Metrics;

/**
 * Adaptador Prometheus del puerto `Metrics`.
 *
 * Añade `env` a todas las series para que staging y producción no se mezclen.
 * Las etiquetas se ordenan por nombre para que dos llamadas al mismo contador
 * siempre declaren el mismo conjunto, que es lo que exige Prometheus.
 */
final class PrometheusMetrics implements Metrics
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $environment,
    ) {}

    public function increment(string $name, array $labels = [], float $by = 1, string $help = ''): void
    {
        [$labelNames, $labelValues] = $this->labels($labels);

        $counter = $this->registry->getOrRegisterCounter(
            '',
            $name,
            $help !== '' ? $help : $name,
            $labelNames,
        );

        $counter->incBy($by, $labelValues);
    }

    public function gauge(string $name, float $value, array $labels = [], string $help = ''): void
    {
        [$labelNames, $labelValues] = $this->labels($labels);

        $gauge = $this->registry->getOrRegisterGauge(
            '',
            $name,
            $help !== '' ? $help : $name,
            $labelNames,
        );

        $gauge->set($value, $labelValues);
    }

    /**
     * @param  array<string, string|int>  $labels
     * @return array{0: list<string>, 1: list<string|int>}
     */
    private function labels(array $labels): array
    {
        $labels['env'] = $this->environment;
        ksort($labels);

        return [array_keys($labels), array_values($labels)];
    }
}
