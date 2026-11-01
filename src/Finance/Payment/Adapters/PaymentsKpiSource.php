<?php

namespace Src\Finance\Payment\Adapters;

use Src\Finance\Payment\Models\Payment;
use Src\Shared\Contracts\KpiSource;
use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

class PaymentsKpiSource implements KpiSource
{
    public function permission(): string
    {
        return 'payments.view';
    }

    public function kpis(AgencyScope $scope): array
    {
        $total = Payment::query()
            ->where('status', Payment::STATUS_REGISTERED)
            ->where('paid_at', '>=', today())
            ->when(! $scope->unrestricted, fn ($query) => $query->whereIn('agency_id', $scope->ids))
            ->sum('amount');

        return [new Kpi('revenue_today', 'Revenue today', number_format((float) $total, 2, '.', ''), 'money')];
    }
}
