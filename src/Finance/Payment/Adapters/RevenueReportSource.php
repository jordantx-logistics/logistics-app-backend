<?php

namespace Src\Finance\Payment\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Finance\Payment\Models\Payment;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

/**
 * Ingresos por día, agencia y medio de pago. Solo cuentan los pagos vigentes: uno
 * anulado o devuelto ya no es ingreso.
 */
class RevenueReportSource implements ReportSource
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function key(): string
    {
        return 'revenue';
    }

    public function title(): string
    {
        return 'Revenue';
    }

    public function columns(): array
    {
        return [
            'day' => 'Day',
            'agency' => 'Agency',
            'payment_method' => 'Method',
            'payments' => 'Payments',
            'total' => 'Total',
            'currency' => 'Currency',
        ];
    }

    public function statuses(): array
    {
        return [];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $rows = Payment::query()
            ->selectRaw('DATE(paid_at) as day, agency_id, payment_method, currency, COUNT(*) as payments, SUM(amount) as total')
            ->where('status', Payment::STATUS_REGISTERED)
            ->whereBetween('paid_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn (Builder $query) => $query->whereIn('agency_id', $filters->agencyIds))
            ->groupByRaw('DATE(paid_at), agency_id, payment_method, currency')
            ->orderBy('day')
            ->orderBy('agency_id')
            ->toBase()
            ->cursor();

        $codes = [];

        foreach ($rows as $row) {
            $agencyId = (int) $row->agency_id;

            yield [
                'day' => $row->day,
                'agency' => $codes[$agencyId] ??= $this->agencies->agencyCode($agencyId) ?? "#{$agencyId}",
                'payment_method' => $row->payment_method,
                'payments' => (int) $row->payments,
                'total' => number_format((float) $row->total, 2, '.', ''),
                'currency' => $row->currency,
            ];
        }
    }
}
