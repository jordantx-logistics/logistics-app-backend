<?php

namespace Src\Shared\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Convierte una tabla plana en particionada sin parar las escrituras más que un momento.
 *
 * Crea `<tabla>__new`, copia por lotes de id y, bajo `LOCK TABLES`, copia lo último y
 * renombra. La original queda como `<tabla>__old`: revertir es renombrar mientras no
 * se borre. Las filas modificadas durante la copia se recuperan por `updated_at`.
 */
class PartitionedTableSwap
{
    public function __construct(private readonly PartitionManager $partitions) {}

    /**
     * @param  string  $alterations  ALTER que deja la copia lista para particionar
     * @return int filas copiadas
     */
    public function run(string $table, string $alterations): int
    {
        if ($this->partitions->partitions($table) !== []) {
            return 0;
        }

        $new = "{$table}__new";
        $old = "{$table}__old";

        if (Schema::hasTable($old)) {
            throw new RuntimeException("{$old} already exists: validate and drop it first.");
        }

        Schema::dropIfExists($new);
        DB::statement("CREATE TABLE `{$new}` LIKE `{$table}`");
        DB::statement("ALTER TABLE `{$new}` {$alterations}");

        $oldest = DB::selectOne("SELECT MIN(created_at) AS oldest FROM `{$table}`")->oldest;
        $from = $oldest !== null ? CarbonImmutable::parse($oldest) : CarbonImmutable::now();
        $months = (int) config('partitions.months_ahead');

        DB::statement("ALTER TABLE `{$new}` ".$this->partitions->definition($from, $months));

        $columns = $this->columnList($table);
        $startedAt = CarbonImmutable::now()->subMinute();
        $batch = max(1, (int) config('partitions.batch'));
        $lastId = 0;
        $copied = 0;

        // Hasta quedarse sin filas; lo que entre después lo recoge el tramo con bloqueo.
        while (($next = $this->nextBoundary($table, $lastId, $batch)) !== null) {
            $copied += $this->copyRange($table, $new, $columns, $lastId, $next);
            $lastId = $next;
        }

        DB::unprepared("LOCK TABLES `{$table}` WRITE, `{$new}` WRITE");

        try {
            $copied += $this->copyRange($table, $new, $columns, $lastId, PHP_INT_MAX);
            $this->syncUpdatedRows($table, $new, $columns, $startedAt);

            $source = (int) DB::selectOne("SELECT COUNT(*) AS total FROM `{$table}`")->total;
            $target = (int) DB::selectOne("SELECT COUNT(*) AS total FROM `{$new}`")->total;

            if ($source !== $target) {
                throw new RuntimeException("{$table}: {$source} rows in the source, {$target} in the copy.");
            }

            DB::unprepared("RENAME TABLE `{$table}` TO `{$old}`, `{$new}` TO `{$table}`");
        } finally {
            DB::unprepared('UNLOCK TABLES');
        }

        return $copied;
    }

    private function nextBoundary(string $table, int $lastId, int $batch): ?int
    {
        $row = DB::selectOne(
            'SELECT MAX(id) AS boundary FROM (SELECT id FROM `'.$table.'` WHERE id > ? ORDER BY id LIMIT '.$batch.') AS page',
            [$lastId],
        );

        return $row->boundary !== null ? (int) $row->boundary : null;
    }

    private function copyRange(string $table, string $new, string $columns, int $after, int $until): int
    {
        // `COALESCE`: la columna admitía NULL antes y ahora es parte de la clave.
        $select = str_replace('`created_at`', 'COALESCE(`created_at`, NOW())', $columns);

        return DB::affectingStatement(
            "INSERT INTO `{$new}` ({$columns}) SELECT {$select} FROM `{$table}` WHERE id > ? AND id <= ?",
            [$after, $until],
        );
    }

    private function syncUpdatedRows(string $table, string $new, string $columns, CarbonImmutable $since): void
    {
        if (! Schema::hasColumn($table, 'updated_at')) {
            return;
        }

        $select = str_replace('`created_at`', 'COALESCE(`created_at`, NOW())', $columns);

        DB::statement(
            "REPLACE INTO `{$new}` ({$columns}) SELECT {$select} FROM `{$table}` WHERE updated_at >= ?",
            [$since],
        );
    }

    private function columnList(string $table): string
    {
        return implode(', ', array_map(fn (string $column) => "`{$column}`", Schema::getColumnListing($table)));
    }
}
