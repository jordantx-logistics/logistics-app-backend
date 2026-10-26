<?php

namespace Src\Operations\Inventory\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Inventory\Models\InventoryCount;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class ListInventoryCountsUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{agency?: int, status?: string, created_from?: string, created_to?: string}  $filters
     */
    public function execute(array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = InventoryCount::query()
            ->forAgencyScope($scope)
            ->withCount('items')
            ->orderByDesc('id');

        if (isset($filters['agency'])) {
            $query->where('agency_id', (int) $filters['agency']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to'].' 23:59:59');
        }

        return $query->paginate($perPage);
    }

    public function find(int $countId, AgencyScope $scope): InventoryCount
    {
        $count = InventoryCount::query()
            ->forAgencyScope($scope)
            ->with('items')
            ->withCount('items')
            ->find($countId);

        if ($count === null) {
            throw new BusinessRuleException(
                message: 'The inventory count does not exist.',
                errorCode: 'INVENTORY_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $count;
    }
}
