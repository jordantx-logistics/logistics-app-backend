<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Scope\AgencyScope;

class ListShipmentsUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Shipment::query()
            ->forAgencyScope($scope)
            ->orderByDesc('id');

        if (isset($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            $query->whereIn('status', $statuses);
        }

        if (isset($filters['agency'])) {
            $agencyId = (int) $filters['agency'];
            $query->where(fn ($query) => $query
                ->where('origin_agency_id', $agencyId)
                ->orWhere('destination_agency_id', $agencyId)
                ->orWhere('current_location_agency_id', $agencyId));
        }

        if (isset($filters['origin_agency_id'])) {
            $query->where('origin_agency_id', (int) $filters['origin_agency_id']);
        }

        if (isset($filters['destination_agency_id'])) {
            $query->where('destination_agency_id', (int) $filters['destination_agency_id']);
        }

        if (isset($filters['ticket_code'])) {
            $query->where('ticket_code', strtoupper((string) $filters['ticket_code']));
        }

        if (isset($filters['ticket_number'])) {
            $query->where('ticket_number', 'like', strtoupper((string) $filters['ticket_number']).'%');
        }

        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to'].' 23:59:59');
        }

        return $query->paginate($perPage);
    }
}
