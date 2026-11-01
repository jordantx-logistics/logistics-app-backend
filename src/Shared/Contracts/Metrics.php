<?php

namespace Src\Shared\Contracts;

/**
 * Puerto mínimo de métricas de dominio.
 *
 * `Shared` no puede importar el exporter de `System` (ADR-0006), pero los
 * contextos necesitan contar cosas que no son peticiones HTTP: aciertos de
 * caché de tarifas, escaneos duplicados, particiones creadas. El adaptador vive
 * en `Src\System\Adapters` y se enlaza en el composition root.
 *
 * El adaptador añade la etiqueta `env` y usa el mismo almacén (Redis) que el
 * resto del exporter, así que los contadores son comunes a todos los workers.
 */
interface Metrics
{
    /**
     * Incrementa un contador monótono.
     *
     * @param  array<string, string|int>  $labels
     */
    public function increment(string $name, array $labels = [], float $by = 1, string $help = ''): void;

    /**
     * Fija el valor de un gauge.
     *
     * @param  array<string, string|int>  $labels
     */
    public function gauge(string $name, float $value, array $labels = [], string $help = ''): void;
}
