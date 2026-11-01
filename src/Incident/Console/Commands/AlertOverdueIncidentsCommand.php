<?php

namespace Src\Incident\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Src\Incident\Models\Incident;
use Src\Shared\Contracts\Metrics;

/**
 * Avisa una sola vez de cada incidencia vencida y publica cuántas hay.
 */
class AlertOverdueIncidentsCommand extends Command
{
    protected $signature = 'incidents:alert-overdue';

    protected $description = 'Log incidents past their deadline and publish the overdue gauge';

    public function handle(Metrics $metrics): int
    {
        Incident::query()
            ->overdue()
            ->whereNull('overdue_alerted_at')
            ->lazyById(200)
            ->each(function (Incident $incident): void {
                Log::warning('incident.overdue', [
                    'incident_id' => $incident->id,
                    'ticket_code' => $incident->ticket_code,
                    'type' => $incident->type,
                    'assigned_to' => $incident->assigned_to,
                    'due_at' => $incident->due_at->toIso8601String(),
                ]);

                $incident->update(['overdue_alerted_at' => now()]);
            });

        $metrics->gauge('incidents_overdue', Incident::query()->overdue()->count(), help: 'Active incidents past their deadline');

        return self::SUCCESS;
    }
}
