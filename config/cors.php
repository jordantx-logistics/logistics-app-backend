<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | La SPA vive en otro origen (`FRONTEND_URL`) y habla con la API **con
    | credenciales** (la cookie de sesión de Sanctum), así que no se pueden usar
    | comodines: el navegador rechaza `*` cuando `supports_credentials` está
    | activo. El origen permitido es el del frontend, en el mismo sitio donde se
    | configura el enlace del correo de recuperación (`config/app.php` →
    | `frontend_url`).
    |
    | Ver `spec/03-roadmap/fase-01-migracion-mvp.md` (paso 1.5) y la Fase 9 para
    | los valores de producción.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [rtrim((string) config('app.frontend_url'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
