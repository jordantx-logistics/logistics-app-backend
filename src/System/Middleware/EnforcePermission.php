<?php

namespace Src\System\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware del alias `permission:`.
 *
 * Envuelve el middleware de Spatie para poder evaluar `authz.enforce` en cada
 * petición: con el flag a `false` (rollback de la Fase 1) la ruta deja pasar
 * sin comprobar el permiso, y `AppServiceProvider` deja constancia en el log.
 * Se lee `config()` en cada petición, no al registrar el alias, para que el
 * interruptor se pueda probar (y activar) sin reconstruir el arranque.
 */
class EnforcePermission
{
    public function __construct(
        private readonly PermissionMiddleware $permission,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! config('authz.enforce')) {
            return $next($request);
        }

        // Spatie acepta varios permisos separados por `|` en el mismo alias.
        return $this->permission->handle($request, $next, implode('|', $permissions));
    }
}
