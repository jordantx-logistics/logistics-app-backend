<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class ListDeliveriesUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{agency?: int, status?: string, courier_id?: int, ticket_code?: string}  $filters
     */
    public function execute(array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Delivery::query()
            ->forAgencyScope($scope)
            ->with('evidence')
            ->orderByDesc('id');

        if (isset($filters['agency'])) {
            $query->where('agency_id', (int) $filters['agency']);
        }

        if (isset($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            $query->whereIn('status', $statuses);
        }

        if (isset($filters['courier_id'])) {
            $query->where('courier_id', (int) $filters['courier_id']);
        }

        if (isset($filters['ticket_code'])) {
            $query->where('ticket_code', strtoupper((string) $filters['ticket_code']));
        }

        return $query->paginate($perPage);
    }

    public function find(int $deliveryId, AgencyScope $scope): Delivery
    {
        $delivery = Delivery::query()
            ->forAgencyScope($scope)
            ->with('evidence')
            ->find($deliveryId);

        if ($delivery === null) {
            throw new BusinessRuleException(
                message: 'The delivery does not exist.',
                errorCode: 'DELIVERY_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $delivery;
    }
}
