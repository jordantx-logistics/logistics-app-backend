<?php

namespace Src\Fleet\Route\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class FleetKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'routes.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        $routes = fn (): Builder => FleetRoute::query()->when(! $scope->unrestricted, fn (Builder $query) => $query->where(
            fn (Builder $visible) => $visible
                ->whereIn('origin_agency_id', $scope->ids)
                ->orWhereIn('destination_agency_id', $scope->ids),
        ));

        return [
            new Kpi('routes_in_progress', 'Routes in progress', (string) $routes()->where('status', FleetRoute::STATUS_IN_PROGRESS)->count()),
            new Kpi(
                'expenses_to_review',
                'Expenses to review',
                (string) Expense::query()
                    ->where('status', Expense::STATUS_REGISTERED)
                    ->whereIn('route_id', $routes()->select('id'))
                    ->count(),
            ),
        ];
    }
}
