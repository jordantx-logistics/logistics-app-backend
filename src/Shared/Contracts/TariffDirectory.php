<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\ResolvedTariff;

/**
 * Puerto hacia `Logistics/Tariff`: resuelve el tarifario aplicable a una ruta.
 *
 * El motor de precios (`Logistics/Order/Services/Pricing`) no conoce el modelo
 * `Tariff` ni sus tablas (ADR-0006): pide el tarifario por este puerto y recibe
 * un DTO plano. La resolución (más específico primero, luego `priority`, luego
 * el más reciente) y la caché viven en el adaptador.
 */
interface TariffDirectory
{
    /**
     * Tarifario vigente que aplica a la ruta y al tipo de servicio.
     *
     * `null` cuando no hay ninguno: el motor decide si eso es un error
     * (`TARIFF_NOT_FOUND`) o si cae al motor legacy.
     */
    public function findForRoute(
        string $serviceType,
        ?int $originZoneId,
        ?int $destinationZoneId,
        ?int $originLocationId,
        ?int $destinationLocationId,
    ): ?ResolvedTariff;
}
