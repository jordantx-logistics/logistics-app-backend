<?php

namespace Src\Operations\Shipment\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class ShipmentsKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'shipments.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        $query = fn (): Builder => Shipment::query()->when(! $scope->unrestricted, fn (Builder $shipments) => $shipments->where(
            fn (Builder $visible) => $visible
                ->whereIn('origin_agency_id', $scope->ids)
                ->orWhereIn('destination_agency_id', $scope->ids),
        ));

        return [
            new Kpi('shipments_to_embark', 'Shipments to embark', (string) $query()->where('status', Shipment::STATUS_RECEIVED)->count()),
            new Kpi('shipments_out_for_delivery', 'Out for delivery', (string) $query()->where('status', Shipment::STATUS_OUT_FOR_DELIVERY)->count()),
        ];
    }
}
