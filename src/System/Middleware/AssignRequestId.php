<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asigna un id de correlación a cada petición y lo devuelve en la respuesta.
 *
 * Si el cliente/proxy ya envía `X-Request-Id` se respeta (para propagar la
 * traza entre servicios); en cualquier otro caso se genera uno nuevo.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestIdFrom($request);

        // `Context` es un `scoped` del contenedor: Octane descarta la instancia al
        // terminar cada petición y Laravel añade estos campos a todas las líneas
        // de log (vía ContextLogProcessor) e incluso a los jobs encolados.
        Context::add('request_id', $requestId);

        $request->headers->set(self::HEADER, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * El patrón permite solo ids simples: evita inyección de líneas en los logs.
     */
    private function requestIdFrom(Request $request): string
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();
    }
}
