<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Src\Operations\Shipment\Models\Embark;
use Src\Shared\Contracts\FleetDirectory;
use Src\Shared\Scope\AgencyScope;

class ListEmbarksUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly FleetDirectory $fleet,
    ) {}

    /**
     * @param  array{agency?: int, departed_from?: string, departed_to?: string, search?: string}  $filters
     */
    public function execute(array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Embark::query()
            ->forAgencyScope($scope)
            ->withCount('items')
            ->with('items')
            ->orderByDesc('departed_at')
            ->orderByDesc('id');

        if (isset($filters['agency'])) {
            $query->where('agency_id', (int) $filters['agency']);
        }

        if (isset($filters['departed_from'])) {
            $query->where('departed_at', '>=', $filters['departed_from']);
        }

        if (isset($filters['departed_to'])) {
            $query->where('departed_at', '<=', $filters['departed_to'].' 23:59:59');
        }

        if (isset($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        if (isset($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($inner) use ($search): void {
                $inner->whereIn('vehicle_id', $this->fleet->vehicleIdsByPlate($search))
                    ->orWhere('route_ref', 'like', '%'.addcslashes($search, '%_\\').'%');
            });
        }

        $page = $query->paginate($perPage);
        $embarks = $page->getCollection();
        $plates = $this->fleet->vehiclePlates($embarks->pluck('vehicle_id')->unique()->values()->all());
        $drivers = $this->fleet->driverNames($embarks->pluck('driver_id')->unique()->values()->all());

        $embarks->each(function (Embark $embark) use ($plates, $drivers): void {
            $embark->setAttribute('vehicle_plate', $plates[$embark->vehicle_id] ?? null);
            $embark->setAttribute('driver_name', $drivers[$embark->driver_id] ?? null);
        });

        return $page;
    }
}
