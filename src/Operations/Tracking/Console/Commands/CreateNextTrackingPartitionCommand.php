<?php

namespace Src\Operations\Tracking\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las particiones mensuales que faltan en `tracking_events`.
 *
 * MySQL no admite `ADD PARTITION` después de una partición `MAXVALUE`, así que
 * la partición comodín `pmax` se reorganiza en cada mes nuevo. En SQLite (tests
 * y suite hermética) la tabla es plana y el comando solo informa que no aplica.
 *
 * Es idempotente: si la partición ya existe, no toca la tabla. Se agenda a
 * diario en lugar de una vez al mes para que un despliegue o un fallo puntual
 * no deje el mes siguiente sin partición.
 */
class CreateNextTrackingPartitionCommand extends Command
{
    protected $signature = 'tracking:create-next-partition
                            {--months=3 : Meses adicionales al actual que deben existir}';

    protected $description = 'Crea las particiones mensuales pendientes de tracking_events.';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->info('tracking_events is not partitioned on this driver; nothing to do.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('tracking_events')) {
            $this->warn('tracking_events does not exist yet; run the migrations first.');

            return self::SUCCESS;
        }

        $months = max(1, (int) $this->option('months'));
        $existing = collect(DB::select(
            "SELECT PARTITION_NAME FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracking_events'
               AND PARTITION_NAME IS NOT NULL"
        ))->pluck('PARTITION_NAME')->all();

        $created = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i <= $months; $i++) {
            $name = 'p'.$cursor->format('Y_m');

            if (! in_array($name, $existing, true)) {
                $next = $cursor->addMonth();
                $boundary = $next->toDateString();

                if (in_array('pmax', $existing, true)) {
                    DB::statement(
                        "ALTER TABLE `tracking_events` REORGANIZE PARTITION `pmax` INTO (
                            PARTITION `{$name}` VALUES LESS THAN (TO_DAYS('{$boundary}')),
                            PARTITION `pmax` VALUES LESS THAN MAXVALUE
                        )"
                    );
                } else {
                    DB::statement(
                        "ALTER TABLE `tracking_events` ADD PARTITION (
                            PARTITION `{$name}` VALUES LESS THAN (TO_DAYS('{$boundary}'))
                        )"
                    );
                }

                $existing[] = $name;
                $created[] = $name;
            }

            $cursor = $cursor->addMonth();
        }

        if ($created === []) {
            $this->info('tracking_events already has the required monthly partitions.');

            return self::SUCCESS;
        }

        $this->info('Created partitions: '.implode(', ', $created));

        return self::SUCCESS;
    }
}
