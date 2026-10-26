<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * Consumidor de outbox en modo daemon para mediciones locales.
 *
 * `schedule:work` arranca un proceso de PHP por pasada; en Docker Desktop
 * (bind mount de Windows/macOS) ese arranque cuesta segundos y falsea la
 * latencia. Este script arranca Laravel una vez y llama al comando en bucle,
 * que es como se comporta un worker persistente en Linux.
 *
 * Uso: `php scripts/outbox-loop.php [segundos]` (por defecto 120).
 * No se usa en producción: allí el rol `scheduler` agenda `outbox:consume`.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$seconds = max(1, (int) ($argv[1] ?? 120));
$end = time() + $seconds;

while (time() < $end) {
    $kernel->call('outbox:consume');
    usleep(250_000);
}
