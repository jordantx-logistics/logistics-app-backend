<?php

namespace Src\Logistics\Order\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class OrdersKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'orders.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        $query = fn (): Builder => Order::query()
            ->when(! $scope->unrestricted, fn (Builder $orders) => $orders->forAgencies($scope->ids));

        return [
            new Kpi('orders_today', 'Orders today', (string) $query()->where('created_at', '>=', today())->count()),
            new Kpi('orders_pending', 'Pending orders', (string) $query()->where('status', Order::STATUS_PENDING)->count()),
            new Kpi('orders_in_transit', 'Orders in transit', (string) $query()->where('status', Order::STATUS_IN_TRANSIT)->count()),
        ];
    }
}
