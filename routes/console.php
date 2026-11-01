<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Instantáneas de Horizon: alimentan sus gráficas (throughput, espera) y serán
// la fuente de los gauges de cola en Prometheus (Fase 1). El paquete registra el
// comando pero no lo programa, así que se agenda aquí; lo ejecuta el rol
// `scheduler` (`php artisan schedule:work`), no la web.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Consumidor del outbox: publica en las colas los eventos que escriben los
// UseCases. Corre cada segundo (y no cada 5 s) porque el SLO es `pending` →
// `processed` con p95 ≤ 5 s: con un lote pequeño, la espera máxima del
// siguiente tick pesa tanto como el despacho. No necesita
// `withoutOverlapping`: dos consumidores a la vez se reparten el lote por
// `lockForUpdate` y, si una pasada se alarga, la siguiente ayuda a drenar.
Schedule::command('outbox:consume')->everySecond()->skip(fn (): bool => (bool) config('outbox.daemon'));

// Gauges que no se pueden calcular en el scrape (backlog de colas, fallos y
// antigüedad del evento pendiente más viejo).
if (config('outbox.daemon')) {
    Schedule::command('metrics:collect')->everyMinute();
} else {
    Schedule::command('metrics:collect')->everyFifteenSeconds();
}

// Particiones: se asegura a diario de que existen los próximos meses. Es idempotente
// y barato; un fallo puntual no deja el mes siguiente sin partición.
Schedule::command('partitions:create-next')->dailyAt('02:15');

// Archivo del outbox y purga de particiones vencidas. Pueden durar horas: en Kubernetes
// tienen su propio CronJob (`k8s/base/maintenance-cronjob.yaml`).
if (config('partitions.maintenance_in_scheduler')) {
    Schedule::command('outbox:archive')->dailyAt('02:30')->withoutOverlapping(1440);
    Schedule::command('partitions:purge')->monthlyOn(1, '03:30')->withoutOverlapping(1440);
}

// Incidencias que superan su plazo: se avisa una vez de cada una.
Schedule::command('incidents:alert-overdue')->everyFifteenMinutes();

// Exportaciones vencidas: retención de 7 días (`config/reporting.php`).
Schedule::command('reports:purge-expired')->dailyAt('03:00');
