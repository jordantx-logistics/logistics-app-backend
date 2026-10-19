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

    /*
    |--------------------------------------------------------------------------
    | Bloqueo por intentos fallidos
    |--------------------------------------------------------------------------
    |
    | `failed_login_attempts` cuenta los fallos consecutivos; al alcanzar
    | `max_login_attempts` la cuenta se bloquea hasta `locked_until`
    | (`lock_minutes`). Un desbloqueo manual (`POST /users/{id}/unlock`) limpia
    | ambos. Reglas `USER_LOCKED` / UC-07 de `spec/04-modulos/identity/07-reglas.md`.
    |
    */

    'max_login_attempts' => (int) env('IDENTITY_MAX_LOGIN_ATTEMPTS', 5),

    'lock_minutes' => (int) env('IDENTITY_LOCK_MINUTES', 15),

];
