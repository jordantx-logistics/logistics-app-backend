<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Shipment\Models\Embark;
use Src\Shared\Scope\AgencyScope;

class ListEmbarksUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{agency?: int, departed_from?: string, departed_to?: string}  $filters
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

        return $query->paginate($perPage);
    }
}
