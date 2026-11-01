<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia Flota para mostrar y buscar vehículos y choferes.
 *
 * Operaciones guarda `vehicle_id` y `driver_id` en sus manifiestos, y el personal de
 * almacén los reconoce por placa y por nombre (ADR-0006). El adaptador vive en
 * `Src\Fleet\Route\Adapters`.
 */
interface FleetDirectory
{
    /**
     * @param  list<int>  $vehicleIds
     * @return array<int, string> placa por id de vehículo
     */
    public function vehiclePlates(array $vehicleIds): array;

    /**
     * Vehículos cuya placa contiene ese texto.
     *
     * @return list<int>
     */
    public function vehicleIdsByPlate(string $plate): array;

    /**
     * @param  list<int>  $driverIds
     * @return array<int, string> nombre por id de chofer
     */
    public function driverNames(array $driverIds): array;
}
