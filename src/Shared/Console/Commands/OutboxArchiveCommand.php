<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Models\OutboxEvent;

/**
 * Mueve los eventos procesados y antiguos a `outbox_events_archive`, que está
 * particionada. Mantiene pequeña la tabla que el consumidor recorre en cada pasada.
 * Los `failed` se quedan: esperan una resolución manual.
 */
class OutboxArchiveCommand extends Command
{
    protected $signature = 'outbox:archive
                            {--batch=5000 : Eventos por transacción}';

    protected $description = 'Archiva los eventos del outbox procesados hace más de los meses configurados.';

    public function handle(): int
    {
        $columns = implode(', ', array_map(
            fn (string $column) => "`{$column}`",
            Schema::getColumnListing('outbox_events'),
        ));
        $limit = now()->subMonths((int) config('partitions.outbox_hot_months'));
        $batch = max(1, (int) $this->option('batch'));
        $moved = 0;

        do {
            $ids = OutboxEvent::query()
                ->where('status', OutboxEvent::STATUS_PROCESSED)
                ->where('processed_at', '<', $limit)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($ids, $columns): void {
                $placeholders = implode(', ', array_fill(0, $ids->count(), '?'));
                $insert = DB::getDriverName() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

                DB::insert(
                    "{$insert} INTO outbox_events_archive ({$columns}) SELECT {$columns} FROM outbox_events WHERE id IN ({$placeholders})",
                    $ids->all(),
                );

                OutboxEvent::query()->whereIn('id', $ids)->delete();
            });

            $moved += $ids->count();
        } while ($ids->count() === $batch);

        $this->info("Archived events: {$moved}.");

        return self::SUCCESS;
    }
}
