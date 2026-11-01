<?php

namespace Src\Incident\Adapters;

use Illuminate\Database\Eloquent\Builder;
use Src\Incident\Models\Incident;
use Src\Shared\Contracts\ReportSource;
use Src\Shared\DTOs\ReportFilters;

class IncidentsReportSource implements ReportSource
{
    public function key(): string
    {
        return 'incidents';
    }

    public function title(): string
    {
        return 'Incidents';
    }

    public function columns(): array
    {
        return [
            'ticket_code' => 'Code',
            'type' => 'Type',
            'priority' => 'Priority',
            'status' => 'Status',
            'description' => 'Description',
            'due_at' => 'Due at',
            'is_overdue' => 'Overdue',
            'resolved_at' => 'Resolved at',
            'created_at' => 'Opened at',
        ];
    }

    public function statuses(): array
    {
        return Incident::STATUSES;
    }

    public function rows(ReportFilters $filters): iterable
    {
        $incidents = Incident::query()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->agencyIds !== null, fn (Builder $query) => $query->where(
                fn (Builder $visible) => $visible
                    ->whereIn('origin_agency_id', $filters->agencyIds)
                    ->orWhereIn('destination_agency_id', $filters->agencyIds),
            ))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status))
            ->lazyById(1000);

        foreach ($incidents as $incident) {
            yield [
                'ticket_code' => $incident->ticket_code,
                'type' => $incident->type,
                'priority' => $incident->priority,
                'status' => $incident->status,
                'description' => $incident->description,
                'due_at' => $incident->due_at->toDateTimeString(),
                'is_overdue' => $incident->isOverdue() ? 'yes' : 'no',
                'resolved_at' => $incident->resolved_at?->toDateTimeString(),
                'created_at' => $incident->created_at?->toDateTimeString(),
            ];
        }
    }
}
