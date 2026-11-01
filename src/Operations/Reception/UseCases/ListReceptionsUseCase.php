<?php

namespace Src\Operations\Reception\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Reception\Models\Reception;
use Src\Shared\Scope\AgencyScope;

class ListReceptionsUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{agency?: int, received_from?: string, received_to?: string}  $filters
     */
    public function execute(array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Reception::query()
            ->forAgencyScope($scope)
            ->withCount('items')
            ->with('items')
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if (isset($filters['agency'])) {
            $query->where('agency_id', (int) $filters['agency']);
        }

        if (isset($filters['received_from'])) {
            $query->where('received_at', '>=', $filters['received_from']);
        }

        if (isset($filters['received_to'])) {
            $query->where('received_at', '<=', $filters['received_to'].' 23:59:59');
        }

        return $query->paginate($perPage);
    }
}
