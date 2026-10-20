<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consumidor del outbox (`outbox:consume`)
    |--------------------------------------------------------------------------
    |
    | El consumidor reclama un lote de eventos pendientes por pasada y los
    | publica en las colas. Los valores por defecto sirven para el volumen
    | actual; se ajustan por entorno sin tocar código.
    |
    */

    'batch' => (int) env('OUTBOX_BATCH', 100),

    // Intentos de publicación antes de marcar el evento como `failed`. Un
    // evento agotado se inspecciona y se reprocesa a mano (spec 05).
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),

    // Backoff exponencial entre intentos: `base * 2^(n-1)` segundos, con techo.
    'backoff' => [
        'base' => (int) env('OUTBOX_BACKOFF_BASE', 5),
        'cap' => (int) env('OUTBOX_BACKOFF_CAP', 600),
    ],

    // Un evento `processing` más viejo que esto se da por abandonado (el
    // consumidor murió a mitad) y vuelve a `pending`.
    'processing_timeout' => (int) env('OUTBOX_PROCESSING_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Colas del sistema
    |--------------------------------------------------------------------------
    |
    | Las mismas que supervisa Horizon (`config/horizon.php`): `high` webhooks y
    | notificaciones de entrega, `default` correos y PDFs, `reports` reportes y
    | exportaciones, `sync` proyecciones entre contextos. `metrics:collect`
    | publica un gauge de backlog por cada una.
    |
    */

    'queues' => ['high', 'default', 'reports', 'sync'],

];
