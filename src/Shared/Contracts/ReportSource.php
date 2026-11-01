<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\ReportFilters;

/**
 * Un reporte que aporta el contexto dueño de sus datos.
 *
 * Reportes no consulta tablas ajenas (ADR-0006): cada contexto implementa sus
 * reportes y el composition root los etiqueta. `rows` es perezoso para que una
 * exportación grande no cargue todo en memoria.
 */
interface ReportSource
{
    /** Identificador estable en la API, p. ej. `orders`. */
    public function key(): string;

    public function title(): string;

    /**
     * Columna → encabezado.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * Estados por los que se puede filtrar; lista vacía si no aplica.
     *
     * @return list<string>
     */
    public function statuses(): array;

    /**
     * Filas con las claves de `columns()`.
     *
     * @return iterable<array<string, scalar|null>>
     */
    public function rows(ReportFilters $filters): iterable;
}
