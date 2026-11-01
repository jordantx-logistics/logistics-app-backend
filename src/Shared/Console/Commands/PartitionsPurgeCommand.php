<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Contracts\Metrics;
use Src\Shared\Services\PartitionArchiver;
use Src\Shared\Services\PartitionManager;
use Throwable;

/**
 * Archiva y borra las particiones vencidas, sin intervención humana.
 *
 * Un `DROP PARTITION` no tiene vuelta atrás. Solo se ejecuta si el archivo se volvió a
 * leer del disco de archivo y coincide en checksum y filas con el primary. Si algo falla,
 * esa partición se queda y el comando termina en error para que se vea en el Job.
 */
class PartitionsPurgeCommand extends Command
{
    protected $signature = 'partitions:purge
                            {--dry-run : Solo lista lo que archivaría y borraría}';

    protected $description = 'Archiva y borra las particiones que superaron su retención.';

    public function handle(PartitionManager $partitions, PartitionArchiver $archiver, Metrics $metrics): int
    {
        if (! $partitions->supported()) {
            $this->info('Partitioning does not apply to this driver; nothing to do.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ((array) config('partitions.tables') as $table => $settings) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($partitions->expired($table, (int) $settings['retention_months']) as $partition) {
                if ($this->option('dry-run')) {
                    $this->line("{$table}.{$partition} would be archived and dropped");

                    continue;
                }

                try {
                    $archive = $archiver->archive($table, $partition);

                    DB::table('partition_archives')->insert([
                        'table_name' => $table,
                        'partition_name' => $partition,
                        'path' => $archive['path'],
                        'disk' => (string) config('partitions.archive_disk'),
                        'row_count' => $archive['rows'],
                        'bytes' => $archive['bytes'],
                        'sha256' => $archive['sha256'],
                        'created_at' => now(),
                    ]);

                    DB::statement("ALTER TABLE `{$table}` DROP PARTITION `{$partition}`");

                    $metrics->increment('partition_dropped_total', ['table' => $table], 1, 'Partitions archived and dropped');
                    Log::info('partitions.dropped', ['table' => $table, 'partition' => $partition] + $archive);
                    $this->info("{$table}.{$partition}: archived {$archive['rows']} rows and dropped");
                } catch (Throwable $error) {
                    $failed = true;
                    Log::error('partitions.purge_failed', ['table' => $table, 'partition' => $partition, 'error' => $error->getMessage()]);
                    $this->error("{$table}.{$partition}: {$error->getMessage()}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
