<?php

namespace Src\Logistics\Order\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

/**
 * Órdenes por agencia y estado. El filtro de agencia alcanza origen y destino,
 * igual que el listado.
 */
class OrdersReportSource implements ReportSource
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function key(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'Orders by agency and status';
    }

    public function columns(): array
    {
        return [
            'ticket_number' => 'Ticket',
            'ticket_code' => 'Code',
            'status' => 'Status',
            'origin' => 'Origin',
            'destination' => 'Destination',
            'service_type' => 'Service',
            'weight_kg' => 'Weight (kg)',
            'total_amount' => 'Total (PEN)',
            'created_at' => 'Created at',
        ];
    }

    public function statuses(): array
    {
        return [
            Order::STATUS_PENDING,
            Order::STATUS_CONFIRMED,
            Order::STATUS_IN_TRANSIT,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
        ];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $orders = Order::query()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn (Builder $query) => $query->forAgencies($filters->agencyIds))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->lazyById(1000);

        $codes = [];

        foreach ($orders as $order) {
            $origin = (int) $order->origin_agency_id;
            $destination = (int) $order->destination_agency_id;

            yield [
                'ticket_number' => $order->ticket_number,
                'ticket_code' => $order->ticket_code,
                'status' => $order->status,
                'origin' => $codes[$origin] ??= $this->agencies->agencyCode($origin) ?? "#{$origin}",
                'destination' => $codes[$destination] ??= $this->agencies->agencyCode($destination) ?? "#{$destination}",
                'service_type' => $order->service_type,
                'weight_kg' => (string) $order->weight_kg,
                'total_amount' => (string) $order->total_amount,
                'created_at' => $order->created_at?->toDateTimeString(),
            ];
        }
    }
}
