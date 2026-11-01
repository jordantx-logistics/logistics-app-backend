<?php

/**
 * Comprueba particiones, purga y cambio de tabla contra MySQL de verdad (la suite usa
 * SQLite, que no particiona). Trabaja con tablas propias y las borra al terminar:
 *
 *   docker compose -f docker-compose.yml -f docker-compose.scale.yml run --rm tools \
 *     php scripts/partition-check.php
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Src\Shared\Services\PartitionedTableSwap;
use Src\Shared\Services\PartitionManager;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = 0;

$check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'OK    ' : 'FALLO ').$label.($detail !== '' ? " ({$detail})" : '').PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$manager = app(PartitionManager::class);
$table = 'partition_check';
$now = CarbonImmutable::now();
$root = sys_get_temp_dir().'/partition-check-'.getmypid();

Schema::dropIfExists($table);
Schema::dropIfExists('partition_check_swap');
Schema::dropIfExists('partition_check_swap__old');
DB::table('partition_archives')->where('table_name', $table)->delete();

DB::statement("CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id, created_at)
) ".$manager->definition($now->subMonths(30), 1));

config([
    'partitions.tables' => [$table => ['retention_months' => 24]],
    'partitions.archive_path' => 'partition-check',
]);

$oldMonth = $now->subMonths(30)->startOfMonth();
$oldPartition = PartitionManager::nameFor($oldMonth);
$keptPartition = PartitionManager::nameFor($now->subMonths(23));

foreach ([$oldMonth, $now->subMonths(23), $now] as $month) {
    DB::table($table)->insert(array_map(
        fn (int $i) => ['note' => "row {$i}", 'created_at' => $month->startOfMonth()->addHours($i)],
        range(1, 300),
    ));
}

// Partición ausente: la fila cae en pmax y la siguiente corrida la recupera.
$future = $now->startOfMonth()->addMonths(6);
DB::table($table)->insert(['note' => 'future', 'created_at' => $future]);
$check('una fila sin partición cae en pmax', $manager->rowsInPmax($table) === 1);
$check('la alerta ve los meses que faltan', $manager->missingAhead($table, 6) === 5);
$created = $manager->createMissing($table, 6);
$check('crea los meses que faltan', count($created) === 5, implode(',', $created));
$check('es idempotente', $manager->createMissing($table, 6) === []);
$check('la reorganización vacía pmax', $manager->rowsInPmax($table) === 0);

$plan = DB::selectOne("EXPLAIN SELECT * FROM {$table} WHERE created_at >= ? AND created_at < ?", [
    $now->startOfMonth(), $now->startOfMonth()->addMonth(),
]);
// Con `TO_DAYS`, MySQL incluye siempre la primera partición, además de la del rango.
$touched = explode(',', (string) $plan->partitions);
$check('una consulta por rango descarta las demás particiones', count($touched) <= 2 && in_array(PartitionManager::nameFor($now), $touched, true), (string) $plan->partitions);

$plan = DB::selectOne(
    'EXPLAIN SELECT * FROM tracking_events WHERE shipment_id = 1 AND created_at >= ?',
    [$now->startOfMonth()],
);
$check('el tracking descarta los meses anteriores', ! str_contains((string) $plan->partitions, 'p'.$now->subMonth()->format('Y_m')), (string) $plan->partitions);

// Purga con un archivo corrupto: no se puede borrar nada.
$local = Storage::build(['driver' => 'local', 'root' => $root]);
$corrupt = new class($local->getDriver(), $local->getAdapter(), $local->getConfig()) extends FilesystemAdapter
{
    public function writeStream($path, $resource, array $options = [])
    {
        return $this->put($path, 'corrupt');
    }
};

Storage::set('check-corrupt', $corrupt);
config(['partitions.archive_disk' => 'check-corrupt']);

$code = Artisan::call('partitions:purge');
$check('con el archivo corrupto, el comando falla', $code === 1);
$check('con el archivo corrupto, no borra', in_array($oldPartition, $manager->partitions($table), true));
$check('con el archivo corrupto, no deja constancia', DB::table('partition_archives')->where('table_name', $table)->doesntExist());

// Purga correcta.
Storage::set('check-good', $local);
config(['partitions.archive_disk' => 'check-good']);

$code = Artisan::call('partitions:purge');
$partitions = $manager->partitions($table);
$archive = DB::table('partition_archives')->where('table_name', $table)->first();

$check('la purga termina bien', $code === 0, trim(Artisan::output()));
$check('borra el mes vencido', ! in_array($oldPartition, $partitions, true));
$check('conserva el mes dentro de la retención', in_array($keptPartition, $partitions, true));
$check('conserva pmax y el mes actual', in_array('pmax', $partitions, true) && in_array(PartitionManager::nameFor($now), $partitions, true));
$check('registra el archivo', $archive !== null && (int) $archive->row_count === 300);

$lines = gzfile($root.'/'.$archive->path);
$check('el archivo contiene las filas', count($lines) === 300 && json_decode($lines[0])->note === 'row 1');
$check('el checksum es el del archivo', hash_file('sha256', $root.'/'.$archive->path) === $archive->sha256);
$check('una segunda purga no hace nada', Artisan::call('partitions:purge') === 0 && DB::table('partition_archives')->where('table_name', $table)->count() < 7);

$rows = (int) DB::table($table)->count();
$expected = 300 * 2 + 1;
$check('solo desaparecen las filas vencidas', $rows <= $expected && $rows >= 300, "{$rows} filas");

// Cambio de tabla con un proceso escribiendo a la vez.
$swap = 'partition_check_swap';
DB::statement("CREATE TABLE {$swap} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    note VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
)");

foreach (array_chunk(range(1, 40000), 2000) as $chunk) {
    DB::table($swap)->insert(array_map(
        fn (int $i) => ['note' => 'seed', 'created_at' => $now->subMonths($i % 3), 'updated_at' => $now->subDays(40)],
        $chunk,
    ));
}

DB::table($swap)->where('id', 39000)->update(['created_at' => null]);
DB::disconnect();

$writer = pcntl_fork();

if ($writer === 0) {
    $written = 0;
    $deadline = microtime(true) + 12;

    while (microtime(true) < $deadline) {
        DB::table($swap)->insert(['note' => 'live', 'created_at' => now(), 'updated_at' => now()]);
        $written++;

        if ($written % 20 === 0) {
            DB::table($swap)->where('id', 5)->update(['note' => 'changed', 'updated_at' => now()]);
        }

        usleep(5000);
    }

    file_put_contents($root.'/written', (string) $written);
    exit(0);
}

usleep(500_000);
$started = microtime(true);
config(['partitions.batch' => 500]);

app(PartitionedTableSwap::class)->run(
    $swap,
    'MODIFY `created_at` DATETIME NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `created_at`)',
);
$elapsed = microtime(true) - $started;

pcntl_waitpid($writer, $status);
$written = (int) file_get_contents($root.'/written');

$check('el cambio queda particionado', $manager->partitions($swap) !== [], sprintf('%.1f s', $elapsed));
$check('el escritor no recibe errores', pcntl_wexitstatus($status) === 0);
$check('el escritor siguió escribiendo durante el cambio', $written > 100, "{$written} filas");

$total = (int) DB::table($swap)->count();
$check('no se pierde ninguna fila', $total === 40000 + $written, "{$total} filas");
$check('la fila sin fecha se conserva', DB::table($swap)->where('id', 39000)->exists());
$check('conserva la fila modificada durante la copia', DB::table($swap)->where('id', 5)->value('note') === 'changed');
$check('la original queda para poder revertir', Schema::hasTable("{$swap}__old"));

Schema::dropIfExists($table);
Schema::dropIfExists($swap);
Schema::dropIfExists("{$swap}__old");
DB::table('partition_archives')->where('table_name', $table)->delete();
exec('rm -rf '.escapeshellarg($root));

echo $failures === 0 ? "Todo correcto.\n" : "{$failures} comprobaciones fallaron.\n";

exit($failures === 0 ? 0 : 1);
