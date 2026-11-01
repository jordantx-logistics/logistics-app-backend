<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Services\PartitionedTableSwap;

/**
 * Particiona una tabla con datos, para las que superan el límite de la migración.
 * La original queda como `<tabla>__old`, y solo `--drop-old` la borra una vez validado.
 */
class PartitionsMigrateCommand extends Command
{
    public const ALTERATIONS = [
        'audit_logs' => 'MODIFY `created_at` DATETIME NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `created_at`)',
        'attendance_records' => 'MODIFY `created_at` DATETIME NOT NULL, DROP INDEX `attendance_records_idempotency_key_unique`, ADD INDEX `attendance_records_idempotency_key_index` (`idempotency_key`), DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `created_at`)',
    ];

    protected $signature = 'partitions:migrate
                            {table : audit_logs o attendance_records}
                            {--drop-old : Borra la copia <tabla>__old de una migración ya validada}';

    protected $description = 'Convierte una tabla existente en particionada copiando por lotes.';

    public function handle(PartitionedTableSwap $swap): int
    {
        $table = (string) $this->argument('table');

        if (! isset(self::ALTERATIONS[$table])) {
            $this->error("{$table} has no partitioning definition.");

            return self::FAILURE;
        }

        if ($this->option('drop-old')) {
            Schema::dropIfExists("{$table}__old");
            $this->info("{$table}__old dropped.");

            return self::SUCCESS;
        }

        $copied = $swap->run($table, self::ALTERATIONS[$table]);

        $this->info("{$table}: {$copied} rows copied. Validate it and run again with --drop-old.");

        return self::SUCCESS;
    }
}
