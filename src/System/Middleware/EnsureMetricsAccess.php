<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege `GET /metrics`.
 *
 * Con `METRICS_TOKEN` definido hay que enviarlo (`Authorization: Bearer` o
 * `?token=`); sin token solo se atiende a IPs privadas/loopback, es decir,
 * desde dentro del clúster. Con el exporter apagado el endpoint no existe.
 */
class EnsureMetricsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('metrics.enabled')) {
            abort(404);
        }

        if (! $this->isAllowed($request)) {
            abort(403, 'Metrics endpoint is not accessible from this origin.');
        }

        return $next($request);
    }

    private function isAllowed(Request $request): bool
    {
        $token = (string) config('metrics.token');

        if ($token !== '') {
            $provided = (string) ($request->bearerToken() ?? $request->query('token'));

            return $provided !== '' && hash_equals($token, $provided);
        }

        $ip = $request->ip();

        // `NO_PRIV_RANGE|NO_RES_RANGE` invalida las IP públicas, así que un
        // resultado `false` significa red privada o reservada (loopback).
        return $ip !== null
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
