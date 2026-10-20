<?php

namespace Src\Logistics\Location\Adapters;

use Src\Logistics\Location\Models\AgencyCoverage;
use Src\Shared\Contracts\CoverageDirectory;

/**
 * Adaptador Eloquent del puerto `CoverageDirectory`.
 *
 * Solo cuentan las coberturas activas con `allows_delivery`: una agencia puede
 * recoger en una ubicación sin repartir a domicilio.
 */
class EloquentCoverageDirectory implements CoverageDirectory
{
    public function deliverySurcharge(int $agencyId, int $locationId): ?float
    {
        $coverage = AgencyCoverage::query()
            ->where('agency_id', $agencyId)
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->first();

        if ($coverage === null || ! $coverage->allows_delivery) {
            return null;
        }

        return (float) $coverage->delivery_surcharge;
    }
}
