<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Envíos que están físicamente en una agencia (inventario operativo).
 */
class AgencyInventoryUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /** Estados que ya no representan un paquete en la agencia. */
    private const CLOSED_STATUSES = [
        Shipment::STATUS_DELIVERED,
        Shipment::STATUS_RETURNED,
        Shipment::STATUS_CANCELLED,
    ];

    /**
     * @param  array{status?: string, search?: string}  $filters
     */
    public function execute(int $agencyId, array $filters, AgencyScope $scope, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        $query = Shipment::query()
            ->where('current_location_agency_id', $agencyId)
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->orderByDesc('id');

        if (isset($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            $query->whereIn('status', $statuses);
        }

        if (isset($filters['search'])) {
            $search = strtoupper((string) $filters['search']);
            $query->where(fn ($query) => $query
                ->where('ticket_code', 'like', $search.'%')
                ->orWhere('ticket_number', 'like', $search.'%'));
        }

        return $query->paginate($perPage);
    }
}
