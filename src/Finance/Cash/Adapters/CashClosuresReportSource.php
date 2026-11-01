<?php

namespace Src\Finance\Cash\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Finance\Cash\Models\CashClosure;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

class CashClosuresReportSource implements ReportSource
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function key(): string
    {
        return 'cash-closures';
    }

    public function title(): string
    {
        return 'Cash closures';
    }

    public function columns(): array
    {
        return [
            'session' => 'Session',
            'agency' => 'Agency',
            'status' => 'Status',
            'expected_amount' => 'Expected (PEN)',
            'closing_amount' => 'Counted (PEN)',
            'difference' => 'Difference (PEN)',
            'requested_at' => 'Requested at',
            'approved_at' => 'Approved at',
        ];
    }

    public function statuses(): array
    {
        return CashClosure::STATUSES;
    }

    public function rows(ReportFilters $filters): iterable
    {
        $closures = CashClosure::query()
            ->select('cash_closures.*', 'cash_sessions.agency_id')
            ->join('cash_sessions', 'cash_sessions.id', '=', 'cash_closures.cash_session_id')
            ->whereBetween('cash_closures.requested_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn (Builder $query) => $query->whereIn('cash_sessions.agency_id', $filters->agencyIds))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('cash_closures.status', $filters->status))
            ->lazyById(1000, 'cash_closures.id', 'id');

        $codes = [];

        foreach ($closures as $closure) {
            $agencyId = (int) $closure->getAttribute('agency_id');

            yield [
                'session' => $closure->cash_session_id,
                'agency' => $codes[$agencyId] ??= $this->agencies->agencyCode($agencyId) ?? "#{$agencyId}",
                'status' => $closure->status,
                'expected_amount' => (string) $closure->expected_amount,
                'closing_amount' => (string) $closure->closing_amount,
                'difference' => (string) $closure->difference,
                'requested_at' => $closure->requested_at?->toDateTimeString(),
                'approved_at' => $closure->approved_at?->toDateTimeString(),
            ];
        }
    }
}
