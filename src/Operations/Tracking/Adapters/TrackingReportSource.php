<?php

namespace Src\Operations\Tracking\Adapters;

use Illuminate\Support\Facades\DB;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

/**
 * Eventos de tracking por rango. El filtro por `created_at` aprovecha las
 * particiones mensuales (ADR-0003). `shipments` es del mismo módulo de Operaciones;
 * se une por tabla para no acoplar los dos subcontextos por sus modelos.
 */
class TrackingReportSource implements ReportSource
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function key(): string
    {
        return 'tracking';
    }

    public function title(): string
    {
        return 'Tracking events';
    }

    public function columns(): array
    {
        return [
            'ticket_code' => 'Code',
            'event_type' => 'Event',
            'status' => 'Status',
            'agency' => 'Agency',
            'created_at' => 'At',
        ];
    }

    public function statuses(): array
    {
        return [];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $events = DB::table('tracking_events')
            ->leftJoin('shipments', 'shipments.id', '=', 'tracking_events.shipment_id')
            ->select('tracking_events.id', 'shipments.ticket_code', 'tracking_events.event_type', 'tracking_events.status', 'tracking_events.agency_id', 'tracking_events.created_at')
            ->whereBetween('tracking_events.created_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn ($query) => $query->whereIn('tracking_events.agency_id', $filters->agencyIds))
            ->lazyById(1000, 'tracking_events.id', 'id');

        $codes = [];

        foreach ($events as $event) {
            $agencyId = $event->agency_id === null ? null : (int) $event->agency_id;

            yield [
                'ticket_code' => $event->ticket_code,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'agency' => $agencyId === null ? null : ($codes[$agencyId] ??= $this->agencies->agencyCode($agencyId) ?? "#{$agencyId}"),
                'created_at' => $event->created_at,
            ];
        }
    }
}
