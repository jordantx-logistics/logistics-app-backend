<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aplicación efectiva de permisos
    |--------------------------------------------------------------------------
    |
    | Interruptor de emergencia del rollback de la Fase 1: con `false`, el alias
    | `permission` apunta a un middleware que deja pasar todas las peticiones
    | (y el arranque lo deja escrito en el log). Está pensado para bajar la
    | autorización mientras se corrige el seeder, no para quedarse así.
    |
    | `bootstrap/app.php` lee esta clave al registrar los alias de middleware.
    |
    */

    'enforce' => env('AUTHZ_ENFORCE', true),

];
