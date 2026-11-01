<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de artefactos fiscales
    |--------------------------------------------------------------------------
    |
    | En local se usa el disco `local`; en producción se puede apuntar a
    | DigitalOcean Spaces/S3 con `FINANCE_DISK=s3` sin cambiar el dominio.
    | Solo se guardan rutas relativas en la base.
    |
    */
    'disk' => env('FINANCE_DISK', 'local'),

    'invoice_path' => env('FINANCE_INVOICE_PATH', 'finance/invoices'),

    'credit_note_path' => env('FINANCE_CREDIT_NOTE_PATH', 'finance/credit-notes'),

    'cash_report_path' => env('FINANCE_CASH_REPORT_PATH', 'finance/cash-reports'),

    /*
    |--------------------------------------------------------------------------
    | SUNAT (Fase 5: gateway simulado)
    |--------------------------------------------------------------------------
    |
    | El adaptador real queda pendiente de certificado y credenciales. El fake
    | permite ejercitar los estados `accepted` y `rejected` en local/CI.
    |
    */
    'sunat' => [
        'gateway' => env('SUNAT_GATEWAY', 'fake'),
        'mode' => env('SUNAT_FAKE_MODE', 'accepted'),
        'endpoint' => env('SUNAT_ENDPOINT', 'https://fake.sunat.local/v1/documents'),
    ],
];
