<?php

namespace Src\Finance\Cash\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashSession;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class CashKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'cash.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        $sessions = fn (): Builder => CashSession::query()
            ->when(! $scope->unrestricted, fn (Builder $query) => $query->whereIn('agency_id', $scope->ids));

        return [
            new Kpi('cash_sessions_open', 'Open cash sessions', (string) $sessions()->where('status', CashSession::STATUS_OPEN)->count()),
            new Kpi(
                'cash_closures_pending',
                'Closures awaiting approval',
                (string) CashClosure::query()
                    ->where('status', CashClosure::STATUS_PENDING)
                    ->whereIn('cash_session_id', $sessions()->select('id'))
                    ->count(),
            ),
        ];
    }
}
