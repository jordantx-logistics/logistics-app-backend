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
