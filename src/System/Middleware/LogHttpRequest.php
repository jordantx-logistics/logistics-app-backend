<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
     * Las rutas usan el guard `api` (JWT), que lanza si no hay token en vez de
     * devolver `null`; por eso se comprueba el token primero. El guard por
     * defecto es `web` (deuda de Fase 1), así que se consulta `api`.
     */
    private function userIdFrom(Request $request): ?int
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        try {
            $user = $request->user('api');
        } catch (Throwable) {
            // Token ausente, malformado o caducado: no hay usuario que registrar.
            return null;
        }

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }
}
