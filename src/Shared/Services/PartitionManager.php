<?php

namespace Src\Shared\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Particiones mensuales `RANGE (TO_DAYS(created_at))` con comodín `pmax`.
 *
 * MySQL no admite `ADD PARTITION` después de `MAXVALUE`, así que cada mes nuevo
 * sale de reorganizar `pmax`. Si `pmax` ya tiene filas, la reorganización las
 * reparte: por eso una partición que faltó se recupera sola en la siguiente corrida.
 */
class PartitionManager
{
    public function supported(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * @return list<string> nombres en orden de la tabla; vacío si no está particionada
     */
    public function partitions(string $table): array
    {
        if (! $this->supported()) {
            return [];
        }

        $rows = DB::select(
            'SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [$table],
        );

        return array_map(fn ($row) => $row->name, $rows);
    }

    /**
     * @return list<string> particiones creadas
     */
    public function createMissing(string $table, int $monthsAhead, ?CarbonImmutable $now = null): array
    {
        $existing = $this->partitions($table);

        if ($existing === []) {
            return [];
        }

        $created = [];
        $cursor = ($now ?? CarbonImmutable::now())->startOfMonth();

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $name = self::nameFor($cursor);

            if (! in_array($name, $existing, true) && $this->isAfterLastMonth($name, $existing)) {
                DB::statement(sprintf(
                    "ALTER TABLE `%s` REORGANIZE PARTITION `pmax` INTO (
                        PARTITION `%s` VALUES LESS THAN (TO_DAYS('%s')),
                        PARTITION `pmax` VALUES LESS THAN MAXVALUE
                    )",
                    $table,
                    $name,
                    $cursor->addMonth()->toDateString(),
                ));

                $existing[] = $name;
                $created[] = $name;
            }

            $cursor = $cursor->addMonth();
        }

        return $created;
    }

    /**
     * Particiones vencidas. Lo que manda es el nombre: nunca se devuelve `pmax`, ni el
     * mes actual ni uno futuro, aunque la retención configurada sea 0.
     *
     * @return list<string>
     */
    public function expired(string $table, int $retentionMonths, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfMonth();
        $limit = $now->subMonths(max(1, $retentionMonths));

        return array_values(array_filter(
            $this->partitions($table),
            function (string $name) use ($limit): bool {
                $month = self::monthOf($name);

                return $month !== null && $month->lessThan($limit);
            },
        ));
    }

    /**
     * Meses futuros que faltan: alimenta la alerta de partición ausente.
     */
    public function missingAhead(string $table, int $monthsAhead, ?CarbonImmutable $now = null): int
    {
        $existing = $this->partitions($table);
        $cursor = ($now ?? CarbonImmutable::now())->startOfMonth();
        $missing = 0;

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $missing += in_array(self::nameFor($cursor), $existing, true) ? 0 : 1;
            $cursor = $cursor->addMonth();
        }

        return $missing;
    }

    public function rowsInPmax(string $table): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS total FROM `{$table}` PARTITION (`pmax`)")->total;
    }

    /**
     * Definición de particiones para una tabla nueva, desde `$from` hasta `$monthsAhead`.
     */
    public function definition(CarbonImmutable $from, int $monthsAhead, ?CarbonImmutable $now = null): string
    {
        $cursor = $from->startOfMonth();
        $last = ($now ?? CarbonImmutable::now())->startOfMonth()->addMonths($monthsAhead);
        $parts = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $parts[] = sprintf(
                "PARTITION `%s` VALUES LESS THAN (TO_DAYS('%s'))",
                self::nameFor($cursor),
                $cursor->addMonth()->toDateString(),
            );
            $cursor = $cursor->addMonth();
        }

        $parts[] = 'PARTITION `pmax` VALUES LESS THAN MAXVALUE';

        return 'PARTITION BY RANGE (TO_DAYS(created_at)) ('.implode(', ', $parts).')';
    }

    public static function nameFor(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Y_m');
    }

    public static function monthOf(string $partition): ?CarbonImmutable
    {
        if (preg_match('/^p(\d{4})_(\d{2})$/', $partition, $match) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], 1, 0, 0, 0);
    }

    /**
     * Un mes anterior al último existente no se puede añadir: quedaría fuera de orden
     * y MySQL lo rechaza. Sus filas ya están en la partición que lo cubre.
     *
     * @param  list<string>  $existing
     */
    private function isAfterLastMonth(string $name, array $existing): bool
    {
        $months = array_filter(array_map(self::monthOf(...), $existing));

        return $months === [] || self::monthOf($name)->greaterThan(max($months));
    }
}
