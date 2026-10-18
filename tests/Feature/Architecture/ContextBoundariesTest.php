<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Fronteras entre contextos
|--------------------------------------------------------------------------
|
| Un contexto es `src/<Contexto>` o `src/<Contexto>/<Subcontexto>` y termina en
| la carpeta de capa (`Models`, `UseCases`, `Adapters`...). Ningún contexto
| puede importar clases de otro: la comunicación es por puerto
| (`Src\Shared\Contracts`) o por evento (ADR-0006 y
| `spec/02-objetivo/01-contextos-delimitados.md`).
|
*/

/** Carpetas de capa que cierran el nombre de un contexto. */
function contextLayerNames(): array
{
    return [
        'Adapters', 'Console', 'Contracts', 'Controllers', 'Events', 'Exceptions',
        'Jobs', 'Logging', 'Mail', 'Middleware', 'Models', 'Pipelines', 'Requests',
        'Resources', 'Rules', 'Services', 'Traits', 'UseCases',
    ];
}

/**
 * Contexto de una ruta relativa a `src/` o de un FQCN sin el prefijo `Src\`.
 * `Logistics/Order/Models/Order.php` → `Logistics\Order`; `Auth/routes.php` → `Auth`.
 */
function contextOf(string $path): string
{
    $segments = explode('/', str_replace('\\', '/', $path));

    // El último segmento es el fichero (.php) o la clase: no es parte del contexto.
    array_pop($segments);

    $context = [];

    foreach ($segments as $segment) {
        if (in_array($segment, contextLayerNames(), true)) {
            break;
        }

        $context[] = $segment;
    }

    return implode('\\', $context);
}

it('no importa clases de otro contexto', function () {
    $violations = [];

    foreach (File::allFiles(base_path('src')) as $file) {
        $source = contextOf($file->getRelativePathname());
        $contents = file_get_contents($file->getPathname());

        preg_match_all('/^use (Src\\\\[A-Za-z0-9_\\\\]+)/m', $contents, $matches);

        foreach ($matches[1] as $imported) {
            $target = contextOf(Str::after($imported, 'Src\\'));

            // Shared es el kernel y puede usarse en todas partes; todo lo demás
            // tiene que ser del propio contexto o pasar por un puerto.
            if ($target === $source || $target === 'Shared') {
                continue;
            }

            $violations[] = sprintf(
                '%s (contexto %s) importa %s (contexto %s)',
                $file->getRelativePathname(),
                $source,
                $imported,
                $target,
            );
        }
    }

    expect($violations)->toBe([], "Importaciones prohibidas entre contextos:\n- ".implode("\n- ", $violations));
});

it('no declara claves foráneas hacia tablas de otro contexto', function () {
    // ADR-0006: `orders.sender_id`/`receiver_id` referencian persons y
    // `payments.order_id` referencia orders, pero sin FOREIGN KEY.
    expect(Schema::getForeignKeys('orders'))->toBe([])
        ->and(Schema::getForeignKeys('payments'))->toBe([]);

    expect(Schema::hasColumns('orders', ['sender_id', 'receiver_id', 'origin_agency_id', 'destination_agency_id', 'created_by']))->toBeTrue()
        ->and(Schema::hasColumns('payments', ['order_id', 'processed_by']))->toBeTrue();
});
