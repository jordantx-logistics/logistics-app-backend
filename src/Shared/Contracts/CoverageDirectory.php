<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia `Logistics/Location` para la cobertura de reparto.
 *
 * El motor de precios necesita saber si la agencia destino entrega a domicilio
 * en una ubicación y con qué recargo. `deliverySurcharge()` devuelve `null`
 * cuando no hay cobertura activa o la agencia no hace reparto ahí: el alta de la
 * orden lo convierte en `DELIVERY_NOT_COVERED` (422).
 */
interface CoverageDirectory
{
    /**
     * Recargo de reparto a domicilio, o `null` si la agencia no cubre la
     * ubicación con reparto.
     */
    public function deliverySurcharge(int $agencyId, int $locationId): ?float;
}
