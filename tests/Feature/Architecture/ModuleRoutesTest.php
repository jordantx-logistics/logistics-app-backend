<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Fronteras de módulos
|--------------------------------------------------------------------------
|
| Un contexto es `src/<Contexto>` o `src/<Contexto>/<Subcontexto>` que tenga
| `routes.php`. `ModuleServiceProvider` los descubre con glob, así que un
| módulo nuevo sin registrar sería un fallo invisible (deuda B11).
|
*/

/**
 * Todos los ficheros de rutas de contexto, en el mismo orden que los carga el provider.
 *
 * @return list<string>
 */
function moduleRouteFiles(): array
{
    $files = array_merge(
        glob(base_path('src/*/routes.php')) ?: [],
        glob(base_path('src/*/*/routes.php')) ?: [],
    );

    sort($files);

    return array_values($files);
}

it('descubre los contextos de uno y de dos niveles bajo src', function () {
    $files = moduleRouteFiles();

    expect($files)->toContain(base_path('src/Identity/User/routes.php'))
        ->and($files)->toContain(base_path('src/Logistics/Order/routes.php'));
});

it('registra en el router los contextos declarados en src', function () {
    $files = moduleRouteFiles();

    expect($files)->not->toBeEmpty();

    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        expect($contents)->toContain('Route::');

        // Solo los prefijos de primer nivel identifican al contexto; los anidados
        // (`roles`, `{id}`...) son relativos al grupo padre.
        preg_match_all("/Route::prefix\('(api\/[^']+)'\)/", $contents, $matches);

        expect($matches[1])->not->toBeEmpty("El contexto {$file} no declara rutas bajo api/.");

        foreach ($matches[1] as $prefix) {
            expect($uris->contains(fn (string $uri) => str_starts_with($uri, $prefix)))
                ->toBeTrue("Ninguna ruta registrada empieza por '{$prefix}' ({$file}): el módulo no se cargó.");
        }
    }
});
