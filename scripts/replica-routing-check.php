<?php

/**
 * Comprueba el enrutamiento contra el stack de escala (`scripts/scale-up.sh`):
 *
 *   docker compose -f docker-compose.yml -f docker-compose.scale.yml run --rm tools \
 *     php scripts/replica-routing-check.php
 *
 * Para la replicación de verdad, así que no se ejecuta contra otra cosa.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = 0;

$check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'OK    ' : 'FALLO ').$label.($detail !== '' ? " ({$detail})" : '').PHP_EOL;
    $failures += $ok ? 0 : 1;
};

$connection = DB::connection();
$replica = new PDO('mysql:host=mysql-replica;port=3306', 'root', env('DB_ROOT_PASSWORD', 'root'));

// Una conexión por muestra: leer `@@server_id` desactiva el multiplexado de ProxySQL y
// deja la sesión fijada a su backend, así que no vería volver a la réplica.
$readServers = function (int $samples = 200) use ($connection): array {
    $seen = [];

    for ($i = 0; $i < $samples; $i++) {
        $connection->disconnect();
        $connection->forgetRecordModificationState();
        $id = (int) $connection->selectOne('select @@server_id as id')->id;
        $seen[$id] = ($seen[$id] ?? 0) + 1;
    }

    $connection->disconnect();

    return $seen;
};

$check('el nodo réplica replica', (bool) $replica->query('SHOW REPLICA STATUS')->fetch(PDO::FETCH_ASSOC));

$seen = $readServers();
$check('las lecturas van a la réplica', ($seen[2] ?? 0) >= 190, json_encode($seen));

$primary = (int) $connection->selectOne('select @@server_id as id', useReadPdo: false)->id;
$check('la conexión de escritura va al primary', $primary === 1, "server_id={$primary}");

$connection->forgetRecordModificationState();
$connection->statement('CREATE TABLE IF NOT EXISTS routing_check (id INT PRIMARY KEY, value INT)');
$connection->statement('REPLACE INTO routing_check VALUES (1, 1)');
$sticky = (int) $connection->selectOne('select @@server_id as id')->id;
$check('tras escribir, la petición sigue en el primary', $sticky === 1, "server_id={$sticky}");

$connection->forgetRecordModificationState();
usleep(500_000);
$value = $connection->selectOne('select value from routing_check where id = 1');
$check('la escritura llega a la réplica', $value !== null && (int) $value->value === 1);

try {
    $connection->getReadPdo()->exec('REPLACE INTO routing_check VALUES (2, 2)');
    $check('el usuario de lectura no puede escribir', false);
} catch (Throwable $error) {
    $check('el usuario de lectura no puede escribir', true);
}

$replica->exec('STOP REPLICA');

$deviated = false;
$started = microtime(true);

while (microtime(true) - $started < 30) {
    sleep(1);
    $seen = $readServers(20);

    if (($seen[2] ?? 0) === 0) {
        $deviated = true;
        break;
    }
}

$check(
    'con la replicación parada, las lecturas se desvían al primary',
    $deviated,
    sprintf('%.1f s', microtime(true) - $started),
);

$replica->exec('START REPLICA');

$returned = false;
$started = microtime(true);

while (microtime(true) - $started < 30) {
    sleep(1);
    $seen = $readServers(20);

    if (($seen[2] ?? 0) > 0) {
        $returned = true;
        break;
    }
}

$check('al recuperarse, la réplica vuelve al pool', $returned, sprintf('%.1f s', microtime(true) - $started));

$connection->statement('DROP TABLE routing_check');

echo $failures === 0 ? "Todo correcto.\n" : "{$failures} comprobaciones fallaron.\n";

exit($failures === 0 ? 0 : 1);
