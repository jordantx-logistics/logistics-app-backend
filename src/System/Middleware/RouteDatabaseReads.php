<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Src\Shared\Services\ReadWriteRouting;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un GET puede leer de la réplica. Cualquier otro método lee del primary desde el
 * principio, porque sus validaciones deciden sobre lo que va a escribir.
 */
class RouteDatabaseReads
{
    public function __construct(private readonly ReadWriteRouting $routing) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            $this->routing->allowReplica();
        } else {
            $this->routing->pinToPrimary();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $request->isMethodSafe() && $user !== null && $response->getStatusCode() < 400) {
            $this->routing->rememberWrite($user->getAuthIdentifier());
        }
    }
}
