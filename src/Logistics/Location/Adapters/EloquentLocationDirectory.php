<?php

namespace Src\Logistics\Location\Adapters;

use Src\Logistics\Location\Models\Location;
use Src\Logistics\Location\Models\Zone;
use Src\Shared\Contracts\LocationDirectory;

/**
 * Adaptador Eloquent del puerto `LocationDirectory`.
 *
 * El ubigeo es único, así que una consulta por columna basta; se mantienen dos
 * métodos porque el motor de tarifas necesita la zona (para elegir tarifa) y la
 * ubicación (para la cobertura de reparto de la agencia destino).
 */
class EloquentLocationDirectory implements LocationDirectory
{
    public function zoneIdForUbigeo(string $ubigeo): ?int
    {
        $zoneId = Location::query()->where('ubigeo', $ubigeo)->value('zone_id');

        return $zoneId === null ? null : (int) $zoneId;
    }

    public function locationIdForUbigeo(string $ubigeo): ?int
    {
        $locationId = Location::query()->where('ubigeo', $ubigeo)->value('id');

        return $locationId === null ? null : (int) $locationId;
    }

    public function zoneExists(int $id): bool
    {
        return Zone::query()->whereKey($id)->exists();
    }

    public function locationExists(int $id): bool
    {
        return Location::query()->whereKey($id)->exists();
    }
}
