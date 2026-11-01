<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tablas particionadas por mes
    |--------------------------------------------------------------------------
    |
    | `retention_months` son los meses en caliente (`06-datos/03-particionado-y-
    | retension.md`). Pasado ese plazo, `partitions:purge` archiva el mes, comprueba
    | el archivo y borra la partición.
    |
    */

    'tables' => [
        'tracking_events' => ['retention_months' => 24],
        'attendance_records' => ['retention_months' => 24],
        'audit_logs' => ['retention_months' => 60],
        'outbox_events_archive' => ['retention_months' => 12],
    ],

    // Meses por delante del actual que siempre tienen que existir. La purga
    // nunca toca el mes actual.
    'months_ahead' => (int) env('PARTITIONS_MONTHS_AHEAD', 3),

    // Espacio de archivo: en producción es Spaces (`s3`).
    'archive_disk' => env('PARTITIONS_ARCHIVE_DISK', env('FILESYSTEM_DISK', 'local')),
    'archive_path' => 'archives/partitions',

    // Filas por lote al exportar o copiar.
    'batch' => (int) env('PARTITIONS_BATCH', 5000),

    // La migración convierte en el acto una tabla hasta este tamaño. Una mayor
    // necesita `partitions:migrate`, que no retiene el despliegue.
    'inline_migration_rows' => (int) env('PARTITIONS_INLINE_MIGRATION_ROWS', 100000),

    // Meses que un evento procesado sigue en `outbox_events` antes de archivarse.
    'outbox_hot_months' => (int) env('OUTBOX_HOT_MONTHS', 3),

    // Purga y archivo de outbox pueden durar horas. En Kubernetes corren en su propio
    // CronJob y no deben bloquear `schedule:run`.
    'maintenance_in_scheduler' => (bool) env('PARTITIONS_MAINTENANCE_IN_SCHEDULER', true),

];
