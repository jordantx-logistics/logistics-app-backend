<?php

namespace Src\System\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Src\Shared\Scope\AgencyScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja el ámbito de agencia del usuario en el contenedor (paso 2.6 de la Fase 2).
 *
 * Va al final del grupo `api`, después de `EnsureFrontendRequestsAreStateful`
 * (que arranca la sesión de la SPA) y antes de los `auth:sanctum` de cada ruta:
 * por eso puede resolver al usuario con el guard `sanctum`, que atiende tanto la
 * cookie de sesión como el token de las apps/PDA. Así los casos de uso reciben
 * el ámbito por constructor en vez de resolver el usuario otra vez.
 *
 * Una petición sin sesión deja un ámbito vacío (`AgencyScope::none()`): no ver
 * nada es el valor por defecto, nunca «verlo todo».
 */
class ResolveAgencyScope
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');

        $this->container->instance(AgencyScope::class, $this->scopeFor($user));

        return $next($request);
    }

    private function scopeFor(?User $user): AgencyScope
    {
        if ($user === null) {
            return AgencyScope::none();
        }

        return $user->hasNationalScope()
            ? AgencyScope::unrestricted()
            : AgencyScope::forAgencies($user->agencyIds());
    }
}
