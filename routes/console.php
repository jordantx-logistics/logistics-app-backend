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
// UseCases. Cada 5 s es lo que pide el SLO de la fase (`order.created` pasa de
// `pending` a `processed` con p95 ≤ 5 s). No necesita `withoutOverlapping`: dos
// consumidores a la vez se reparten el lote por `lockForUpdate`.
Schedule::command('outbox:consume')->everyFiveSeconds();

// Gauges que no se pueden calcular en el scrape (backlog de colas, fallos y
// antigüedad del evento pendiente más viejo).
Schedule::command('metrics:collect')->everyFifteenSeconds();
