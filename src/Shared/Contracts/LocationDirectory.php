<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia `Logistics/Location` (zonas y ubicaciones).
 *
 * Lo usa el motor de tarifas (`Logistics/Order`) para pasar de un ubigeo al
 * `location_id` y a la `zone_id` sin consultar tablas de otro contexto
 * (ADR-0006). El adaptador vive en `Src\Logistics\Location\Adapters`.
 */
interface LocationDirectory
{
    /**
     * Zona a la que pertenece el ubigeo, o `null` si no está en el padrón.
     */
    public function zoneIdForUbigeo(string $ubigeo): ?int;

    /**
     * Id de la ubicación del ubigeo, o `null` si no está en el padrón.
     */
    public function locationIdForUbigeo(string $ubigeo): ?int;
}
