<?php

namespace Src\Incident\Adapters;

use Src\Incident\Models\Incident;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class IncidentsKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'incidents.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        return [
            new Kpi('incidents_active', 'Active incidents', (string) Incident::query()->forScope($scope)->whereIn('status', Incident::ACTIVE_STATUSES)->count()),
            new Kpi('incidents_overdue', 'Overdue incidents', (string) Incident::query()->forScope($scope)->overdue()->count()),
        ];
    }
}
