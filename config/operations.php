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
];
