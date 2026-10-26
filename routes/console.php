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
Schedule::command('outbox:consume')->everySecond();

// Gauges que no se pueden calcular en el scrape (backlog de colas, fallos y
// antigüedad del evento pendiente más viejo).
Schedule::command('metrics:collect')->everyFifteenSeconds();

// Particiones de tracking: se asegura a diario de que existen los próximos
// meses. Es idempotente y barato; un fallo puntual no deja el mes siguiente sin
// partición.
Schedule::command('tracking:create-next-partition')->dailyAt('02:15');
