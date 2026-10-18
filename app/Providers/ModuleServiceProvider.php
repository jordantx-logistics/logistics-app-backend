<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleCommands();
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

    /**
     * Registra los comandos de artesano de cada contexto bajo `src/`.
     *
     * Mismo espíritu que las rutas: un módulo que añade
     * `src/<Contexto>/Console/Commands/FooCommand.php` —o
     * `src/<Contexto>/<Subcontexto>/…`— queda disponible sin tocar esta clase.
     * De la ruta se deduce el FQCN y se descarta el fichero que no declara la
     * clase que le corresponde (un helper en esa carpeta no rompe el arranque).
     */
    private function loadModuleCommands(): void
    {
        $files = array_merge(
            glob(base_path('src/*/Console/Commands/*.php')) ?: [],
            glob(base_path('src/*/*/Console/Commands/*.php')) ?: [],
        );

        sort($files);

        $prefix = Str::finish(str_replace('\\', '/', base_path('src')), '/');

        $commands = [];

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file);

            if (! str_starts_with($relative, $prefix)) {
                continue;
            }

            $class = 'Src\\'.str_replace(['/', '.php'], ['\\', ''], substr($relative, strlen($prefix)));

            if (class_exists($class)) {
                $commands[] = $class;
            }
        }

        if ($commands !== []) {
            $this->commands($commands);
        }
    }
}
