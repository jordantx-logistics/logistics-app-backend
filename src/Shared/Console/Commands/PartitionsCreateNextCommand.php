<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Contracts\Metrics;
use Src\Shared\Services\PartitionManager;

/**
 * Crea las particiones que faltan e informa del estado a Prometheus. Es idempotente y
 * se agenda a diario: un fallo puntual no deja el mes siguiente sin partición.
 */
class PartitionsCreateNextCommand extends Command
{
    protected $signature = 'partitions:create-next';

    protected $description = 'Crea las particiones mensuales pendientes de las tablas particionadas.';

    public function handle(PartitionManager $partitions, Metrics $metrics): int
    {
        if (! $partitions->supported()) {
            $this->info('Partitioning does not apply to this driver; nothing to do.');

            return self::SUCCESS;
        }

        $months = (int) config('partitions.months_ahead');

        foreach (array_keys((array) config('partitions.tables')) as $table) {
            if (! Schema::hasTable($table) || $partitions->partitions($table) === []) {
                $this->warn("{$table} is not partitioned yet.");

                continue;
            }

            $created = $partitions->createMissing($table, $months);

            if ($created !== []) {
                $metrics->increment('partition_created_total', ['table' => $table], count($created), 'Partitions created');
                $this->info("{$table}: created ".implode(', ', $created));
            }

            $metrics->gauge('partition_missing', $partitions->missingAhead($table, $months), ['table' => $table], 'Future months without a partition');
            $metrics->gauge('partition_pmax_rows', $partitions->rowsInPmax($table), ['table' => $table], 'Rows that fell into the catch-all partition');
        }

        return self::SUCCESS;
    }
}
