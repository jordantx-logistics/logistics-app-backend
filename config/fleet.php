<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de evidencias
    |--------------------------------------------------------------------------
    |
    | Comprobantes y fotos de odómetro. En local se usa el disco `local`; en
    | producción se apunta a DigitalOcean Spaces/S3 con `FLEET_DISK=s3`. Solo se
    | guardan rutas relativas en la base.
    |
    */
    'disk' => env('FLEET_DISK', 'local'),

    'evidence_path' => env('FLEET_EVIDENCE_PATH', 'fleet'),

    /*
    |--------------------------------------------------------------------------
    | Tope de viático por ruta (PEN)
    |--------------------------------------------------------------------------
    |
    | Valor inicial provisional: falta confirmar la política interna.
    |
    */
    'per_diem_max' => env('FLEET_PER_DIEM_MAX', '500.00'),

    /*
    |--------------------------------------------------------------------------
    | Rango normal de consumo por tipo de vehículo (km/L)
    |--------------------------------------------------------------------------
    |
    | Fuera del rango se genera una alerta, sin bloquear la operación. Son valores
    | provisionales, a validar con quien conozca la flota.
    |
    */
    'consumption' => [
        'truck' => [
            'min' => (float) env('FLEET_CONSUMPTION_TRUCK_MIN', 2),
            'max' => (float) env('FLEET_CONSUMPTION_TRUCK_MAX', 6),
        ],
        'van' => [
            'min' => (float) env('FLEET_CONSUMPTION_VAN_MIN', 6),
            'max' => (float) env('FLEET_CONSUMPTION_VAN_MAX', 14),
        ],
        'trailer' => [
            'min' => (float) env('FLEET_CONSUMPTION_TRAILER_MIN', 1.5),
            'max' => (float) env('FLEET_CONSUMPTION_TRAILER_MAX', 5),
        ],
    ],
];
