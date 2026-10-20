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

    /**
     * ¿Existe la agencia y está activa?
     *
     * Lo exige el alta de empleado (`EMPLOYEE_AGENCY_INVALID`) y, más adelante,
     * la resolución de tarifas por agencia.
     */
    public function agencyIsActive(int $id): bool;

    /**
     * Ubigeo del distrito de la agencia, si lo tiene.
     *
     * Es la primera pieza de la resolución de tarifas (agencia → ubigeo →
     * `location` → zona). Las agencias del MVP no lo tienen (`null`), así que el
     * motor cae al tarifario nacional.
     */
    public function agencyUbigeo(int $id): ?string;
}
