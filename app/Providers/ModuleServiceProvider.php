<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadModuleRoutes();
    }

    /**
     * Carga el `routes.php` de cada contexto bajo `src/`.
     *
     * Un contexto es `src/<Contexto>` o `src/<Contexto>/<Subcontexto>`, por eso se
     * buscan los dos niveles. El descubrimiento es automático: un módulo nuevo con
     * `routes.php` queda accesible sin tocar esta clase (deuda B11 resuelta).
     */
    private function loadModuleRoutes(): void
    {
        $modules = array_merge(
            glob(base_path('src/*/routes.php')) ?: [],
            glob(base_path('src/*/*/routes.php')) ?: [],
        );

        // glob() no garantiza el mismo orden en todos los sistemas de ficheros;
        // ordenar mantiene el registro de rutas estable entre entornos.
        sort($modules);

        foreach ($modules as $routeFile) {
            $this->loadRoutesFrom($routeFile);
        }
    }
}
