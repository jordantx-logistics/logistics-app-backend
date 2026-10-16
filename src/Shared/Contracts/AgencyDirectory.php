<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia el contexto `Organization` (agencias).
 *
 * El contexto `Logistics/Order` valida que las agencias de origen y destino
 * existen sin consultar la tabla `agencies` (ADR-0006). El adaptador que conoce
 * ese modelo vive en `Src\Organization\Adapters` y se enlaza en el composition
 * root (`app/Providers/DomainServiceProvider.php`).
 */
interface AgencyDirectory
{
    /**
     * ¿Existe una agencia con ese id?
     */
    public function agencyExists(int $id): bool;
}
