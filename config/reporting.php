<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de exportaciones
    |--------------------------------------------------------------------------
    |
    | En local se usa el disco `local`; en producción, DigitalOcean Spaces/S3 con
    | `REPORTS_DISK=s3`. La descarga pasa siempre por un endpoint autenticado.
    |
    */
    'disk' => env('REPORTS_DISK', 'local'),

    'path' => env('REPORTS_PATH', 'reports'),

    // Días que se conserva el archivo; `reports:purge-expired` lo borra después.
    'retention_days' => (int) env('REPORTS_RETENTION_DAYS', 7),

    // Rango máximo de una exportación.
    'max_range_days' => (int) env('REPORTS_MAX_RANGE_DAYS', 366),

    // dompdf arma el documento en memoria: por encima de este tope se pide CSV o Excel.
    'pdf_max_rows' => (int) env('REPORTS_PDF_MAX_ROWS', 2000),

    // Segundos de caché de los KPIs del dashboard, por fuente y ámbito.
    'kpi_cache_ttl' => (int) env('REPORTS_KPI_CACHE_TTL', 60),
];
