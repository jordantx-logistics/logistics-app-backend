<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Src\System\Services\Metrics\MetricsRecorder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Alimenta las métricas HTTP (`http_requests_total`,
 * `http_request_duration_seconds`, `octane_workers_busy`).
 *
 * La etiqueta `route` es el patrón de la ruta (`api/v1/orders/{order}`), nunca
 * la URL: si no, cada id crearía una serie temporal distinta.
 */
class RecordHttpMetrics
{
    private const UNMATCHED_ROUTE = 'unmatched';

    public function __construct(
        private readonly MetricsRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldMeasure($request)) {
            return $next($request);
        }

        $startedAt = hrtime(true);

        $this->safely(fn () => $this->recorder->requestStarted());

        try {
            $response = $next($request);
        } finally {
            $this->safely(fn () => $this->recorder->requestFinished());
        }

        $this->safely(fn () => $this->recorder->recordRequest(
            method: $request->getMethod(),
            route: $request->route()?->uri() ?? self::UNMATCHED_ROUTE,
            status: $response->getStatusCode(),
            durationSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
        ));

        return $response;
    }

    private function shouldMeasure(Request $request): bool
    {
        return (bool) config('metrics.enabled')
            && ! in_array($request->path(), (array) config('metrics.ignored_paths'), true);
    }

    /**
     * El exporter nunca debe cambiar la respuesta: si el almacén falla se deja
     * constancia y la petición sigue.
     */
    private function safely(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('metrics_unavailable', ['error' => $e->getMessage()]);
        }
    }
}
