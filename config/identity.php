<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dispositivos por usuario
    |--------------------------------------------------------------------------
    |
    | Máximo de dispositivos activos que un usuario puede tener a la vez
    | (`spec/04-modulos/identity/02-entidades.md`, regla `DEVICE_LIMIT_REACHED`).
    | Es config y no constante para poder ajustarlo por entorno/operación.
    |
    */

    'device_limit' => (int) env('IDENTITY_DEVICE_LIMIT', 5),

];
