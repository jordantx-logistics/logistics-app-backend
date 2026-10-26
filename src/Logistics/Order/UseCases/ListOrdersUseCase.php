<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Scope\AgencyScope;

/**
 * Listado de órdenes, de la más reciente a la más antigua.
 *
 * El ámbito de agencia llega por constructor: lo deja el middleware
 * `Src\System\Middleware\ResolveAgencyScope` en el contenedor a partir del
 * usuario autenticado, así el caso de uso no depende de `App\Models\User` ni de
 * la petición.
 *
 * Los filtros son acumulativos y nunca amplían el ámbito: una orden de otra
 * agencia no aparece aunque se pida su `origin_agency_id`.
 */
class ListOrdersUseCase
{
    /** Página por defecto cuando la petición no manda `per_page`. */
    public const DEFAULT_PER_PAGE = 15;

    /** Tope de `per_page`: el listado no puede pedir la tabla entera. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyScope $scope,
        private readonly CustomerDirectory $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Order::query()->forAgencyScope($this->scope);

        $statuses = $filters['status'] ?? null;

        if (is_string($statuses)) {
            $statuses = explode(',', $statuses);
        }

        if (is_array($statuses) && $statuses !== []) {
            $statuses = array_values(array_filter(array_map(
                fn (mixed $status): string => trim((string) $status),
                $statuses,
            ), fn (string $status): bool => $status !== ''));

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if (isset($filters['origin_agency_id'])) {
            $query->where('origin_agency_id', (int) $filters['origin_agency_id']);
        }

        if (isset($filters['destination_agency_id'])) {
            $query->where('destination_agency_id', (int) $filters['destination_agency_id']);
        }

        if (isset($filters['agency'])) {
            $agency = (int) $filters['agency'];

            $query->where(function ($query) use ($agency): void {
                $query->where('origin_agency_id', $agency)
                    ->orWhere('destination_agency_id', $agency);
            });
        }

        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }

        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }

        if (isset($filters['sender_id'])) {
            $query->where('sender_id', (int) $filters['sender_id']);
        }

        if (isset($filters['receiver_id'])) {
            $query->where('receiver_id', (int) $filters['receiver_id']);
        }

        if (isset($filters['sender_document'])) {
            $ids = $this->customers->customerIdsByDocument(
                (string) $filters['sender_document'],
                $filters['sender_document_type'] ?? null,
            );

            $query->whereIn('sender_id', $ids);
        }

        if (isset($filters['receiver_document'])) {
            $ids = $this->customers->customerIdsByDocument(
                (string) $filters['receiver_document'],
                $filters['receiver_document_type'] ?? null,
            );

            $query->whereIn('receiver_id', $ids);
        }

        if (isset($filters['ticket_code']) && trim((string) $filters['ticket_code']) !== '') {
            $query->where('ticket_code', strtoupper(trim((string) $filters['ticket_code'])));
        }

        if (isset($filters['ticket_number']) && trim((string) $filters['ticket_number']) !== '') {
            $query->where('ticket_number', 'like', trim((string) $filters['ticket_number']).'%');
        }

        if (isset($filters['ticket']) && trim((string) $filters['ticket']) !== '') {
            $ticket = trim((string) $filters['ticket']);

            $query->where(function ($query) use ($ticket): void {
                $query->where('ticket_code', strtoupper($ticket))
                    ->orWhere('ticket_number', 'like', $ticket.'%');
            });
        }

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
