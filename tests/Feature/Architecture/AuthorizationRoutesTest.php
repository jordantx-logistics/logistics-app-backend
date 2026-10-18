<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autorización de las rutas de negocio
|--------------------------------------------------------------------------
|
| Criterio 4 de la Fase 1: el 100% de las rutas de negocio llevan
| `auth:sanctum` **y** un `permission:` cuyo nombre existe en el catálogo del
| seeder. Las rutas que no son de negocio (readiness y auth) están enumeradas a
| propósito: al añadir una ruta nueva sin permiso, esto falla.
|
*/

/** Rutas `api/v1` completas (patrón de la URL, no ejemplos). */
function apiV1Routes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/'));
}

/** Rutas públicas o de sesión que no pasan por `permission:`. */
function isNonBusinessRoute(RoutingRoute $route): bool
{
    return str_starts_with($route->uri(), 'api/v1/auth/')
        || $route->uri() === 'api/v1/health';
}

/** Rutas de negocio: las que exigen un permiso. */
function businessRoutes(): Collection
{
    return apiV1Routes()->reject(fn (RoutingRoute $route) => isNonBusinessRoute($route))->values();
}

/**
 * Permisos declarados en el middleware de una ruta (`permission:a|b`).
 *
 * @return list<string>
 */
function routePermissions(RoutingRoute $route): array
{
    return collect($route->gatherMiddleware())
        ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'))
        ->flatMap(fn ($middleware) => explode('|', substr($middleware, strlen('permission:'))))
        ->values()
        ->all();
}

it('protege cada ruta de negocio con auth:sanctum y un permiso del catálogo', function () {
    $missingAuth = [];
    $missingPermission = [];
    $unknownPermission = [];

    foreach (businessRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:sanctum', $middleware, true)) {
            $missingAuth[] = $route->uri();
        }

        $permissions = routePermissions($route);

        if ($permissions === []) {
            $missingPermission[] = $route->uri();
        }

        foreach (array_diff($permissions, PermissionSeeder::PERMISSIONS) as $unknown) {
            $unknownPermission[] = $route->uri().' → '.$unknown;
        }
    }

    expect(businessRoutes())->not->toBeEmpty()
        ->and($missingAuth)->toBe([])
        ->and($missingPermission)->toBe([])
        ->and($unknownPermission)->toBe([]);
});

it('falla si aparece una ruta de negocio sin permission:', function () {
    // La regla tiene que detectar de verdad una ruta despistada: si no, el test
    // de arriba pasaría por estar vacío. La ruta se registra solo en este test
    // (cada test arranca su propia aplicación).
    Route::middleware('auth:sanctum')->get('api/v1/temp-sin-permiso', fn () => 'ok');

    $sinPermiso = businessRoutes()
        ->filter(fn (RoutingRoute $route) => routePermissions($route) === [])
        ->map(fn (RoutingRoute $route) => $route->uri())
        ->all();

    expect($sinPermiso)->toContain('api/v1/temp-sin-permiso');
});

it('deja los endpoints de auth como públicos con throttle o con sesión', function () {
    $authRoutes = apiV1Routes()->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/auth/'));

    expect($authRoutes)->not->toBeEmpty();

    $exposed = $authRoutes->reject(function (RoutingRoute $route) {
        $middleware = $route->gatherMiddleware();

        return in_array('throttle:auth', $middleware, true)
            || in_array('auth:sanctum', $middleware, true);
    })->map(fn (RoutingRoute $route) => $route->uri());

    expect($exposed->all())->toBe([]);
});

it('desactiva la comprobación de permisos con AUTHZ_ENFORCE=false', function () {
    $user = adminUser();
    $user->removeRole('admin'); // sin permisos: la ruta debe responder 403

    $this->actingAs($user)->getJson('/api/v1/organization/agencies')->assertForbidden();

    // Interruptor de emergencia del rollback de la Fase 1. Se evalúa por
    // petición, así que basta con tocar la config para comprobarlo.
    config(['authz.enforce' => false]);

    $this->actingAs($user)->getJson('/api/v1/organization/agencies')->assertOk();
});
