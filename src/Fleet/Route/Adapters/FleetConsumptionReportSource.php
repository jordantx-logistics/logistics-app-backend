<?php

namespace Src\Fleet\Route\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\RouteSummaryService;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

/**
 * Consumo y coste de las rutas terminadas en el rango.
 */
class FleetConsumptionReportSource implements ReportSource
{
    public function __construct(
        private readonly RouteSummaryService $summaries,
    ) {}

    public function key(): string
    {
        return 'fleet-consumption';
    }

    public function title(): string
    {
        return 'Fleet consumption';
    }

    public function columns(): array
    {
        return [
            'ticket_number' => 'Route',
            'plate' => 'Vehicle',
            'finished_at' => 'Finished at',
            'km' => 'Km',
            'liters' => 'Liters',
            'km_per_liter' => 'Km/L',
            'fuel_cost' => 'Fuel (PEN)',
            'per_diem_cost' => 'Per diem (PEN)',
            'total_cost' => 'Total (PEN)',
            'cost_per_km' => 'Cost/km (PEN)',
            'is_anomalous' => 'Anomalous',
        ];
    }

    public function statuses(): array
    {
        return [];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $routes = FleetRoute::query()
            ->where('status', FleetRoute::STATUS_FINISHED)
            ->whereBetween('finished_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn (Builder $query) => $query->where(
                fn (Builder $visible) => $visible
                    ->whereIn('origin_agency_id', $filters->agencyIds)
                    ->orWhereIn('destination_agency_id', $filters->agencyIds),
            ))
            ->lazyById(500);

        $plates = [];

        foreach ($routes as $route) {
            $summary = $this->summaries->for($route);
            $vehicleId = (int) $route->vehicle_id;

            yield [
                'ticket_number' => $route->ticket_number,
                'plate' => $plates[$vehicleId] ??= Vehicle::query()->whereKey($vehicleId)->value('plate'),
                'finished_at' => $route->finished_at?->toDateTimeString(),
                'km' => $summary['km'],
                'liters' => $summary['liters'],
                'km_per_liter' => $summary['km_per_liter'],
                'fuel_cost' => $summary['fuel_cost'],
                'per_diem_cost' => $summary['per_diem_cost'],
                'total_cost' => $summary['total_cost'],
                'cost_per_km' => $summary['cost_per_km'],
                'is_anomalous' => $summary['is_anomalous'] ? 'yes' : 'no',
            ];
        }
    }
}
