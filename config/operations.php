<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Operaciones
    |--------------------------------------------------------------------------
    |
    | Intentos de entrega antes de devolver el envío al remitente. El contador
    | vive en `deliveries.attempt`; al superar el máximo el envío pasa a
    | `returned` y se cierra el ciclo de reparto.
    |
    */
    'delivery_max_attempts' => (int) env('OPERATIONS_DELIVERY_MAX_ATTEMPTS', 3),

    /*
    | Firma y foto de las entregas. En local, el disco `local`; en producción,
    | DigitalOcean Spaces/S3 con `OPERATIONS_DISK=s3`. La base guarda rutas relativas.
    */
    'disk' => env('OPERATIONS_DISK', 'local'),
];
