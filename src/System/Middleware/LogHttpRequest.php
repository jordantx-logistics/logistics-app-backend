<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja una línea de log por petición con método, ruta, estado y duración.
 *
 * El `request_id` y el `user_id` viven en `Context` y los añade Laravel a todas
 * las líneas, así que esta se puede correlacionar con el resto.
 */
class LogHttpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        if (($userId = $this->userIdFrom($request)) !== null) {
            Context::add('user_id', $userId);
        }

        Log::info('http_request', [
            'method' => $request->getMethod(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'ip' => $request->ip(),
        ]);

        return $response;
    }

    /**
     * El usuario se lee **después** de `$next`: si la ruta lleva `auth:sanctum`,
     * Sanctum ya resolvió la sesión o el bearer y `$request->user()` devuelve el
     * usuario. Con el guard JWT (`api`) de la Fase 0 la comprobación era otra;
     * ese guard ya no existe.
     */
    private function userIdFrom(Request $request): ?int
    {
        $user = $request->user();

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }
}
