<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\RouteSnapshot;

/**
 * Puerto hacia `Fleet/Route`.
 *
 * Operaciones valida el `route_ref` de un embarque sin consultar la tabla de
 * rutas (ADR-0006). El adaptador vive en `Src\Fleet\Route\Adapters`.
 */
interface RouteDirectory
{
    public function findByTicketNumber(string $ticketNumber): ?RouteSnapshot;
}
