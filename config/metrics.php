<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoint de métricas
    |--------------------------------------------------------------------------
    |
    | `GET /metrics` expone las métricas en formato Prometheus. Se puede apagar
    | sin tocar código (así un exporter que estorbe nunca bloquea la API): con
    | `false` el endpoint responde 404.
    |
    */

    'enabled' => env('METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Acceso
    |--------------------------------------------------------------------------
    |
    | Con `METRICS_TOKEN` definido, el scrape tiene que enviarlo
    | (`Authorization: Bearer <token>` o `?token=`). Sin token, solo se atiende
    | a IPs privadas/loopback, es decir, desde dentro del clúster: el `Service`
    | no se publica en el Ingress (Fase 9).
    |
    | Aviso: si la app queda detrás de un proxy sin `TrustProxies`, la IP que se
    | ve es la del proxy (privada). Por eso, en cuanto haya un balanceador
    | delante, define siempre `METRICS_TOKEN`.
    |
    */

    'token' => env('METRICS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Almacén
    |--------------------------------------------------------------------------
    |
    | `redis` comparte los contadores entre todos los workers de Octane;
    | `in-memory` los deja en el proceso (tests).
    |
    */

    'storage' => env('METRICS_STORAGE', 'redis'),

    'redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Histograma de latencia
    |--------------------------------------------------------------------------
    |
    | Buckets en segundos (el SLO base es p95 ≤ 500 ms).
    |
    */

    'buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0],

    /*
    |--------------------------------------------------------------------------
    | Rutas excluidas
    |--------------------------------------------------------------------------
    |
    | El propio scrape no se mide: inflaría los contadores que se consultan.
    |
    */

    'ignored_paths' => ['metrics'],

];
