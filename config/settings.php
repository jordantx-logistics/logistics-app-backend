<?php

/*
|--------------------------------------------------------------------------
| Catálogo de parámetros editables
|--------------------------------------------------------------------------
|
| Cada clave coincide con la de `config()`: sin fila en `settings`, manda el
| valor de la configuración (y por tanto el del entorno). Lo que no esté aquí no
| se puede editar desde la API.
|
*/

$consumption = static fn (string $type, string $label): array => [
    "fleet.consumption.{$type}.min" => [
        'group' => 'fleet',
        'label' => "{$label}: minimum consumption (km/L)",
        'type' => 'decimal',
        'min' => 0.1,
        'max' => 100,
    ],
    "fleet.consumption.{$type}.max" => [
        'group' => 'fleet',
        'label' => "{$label}: maximum consumption (km/L)",
        'type' => 'decimal',
        'min' => 0.1,
        'max' => 100,
    ],
];

return [
    'catalog' => [
        'fleet.per_diem_max' => [
            'group' => 'fleet',
            'label' => 'Per diem limit per route (PEN)',
            'type' => 'decimal',
            'min' => 0,
            'max' => 100000,
        ],
        ...$consumption('truck', 'Truck'),
        ...$consumption('van', 'Van'),
        ...$consumption('trailer', 'Trailer'),
        'pricing.engine' => [
            'group' => 'pricing',
            'label' => 'Pricing engine',
            'type' => 'enum',
            'options' => ['tariff', 'legacy'],
        ],
    ],

    /*
    | Segundos que un worker puede seguir con el valor en caché si la invalidación
    | no llegara a Redis. Guardar un parámetro la invalida al instante.
    */
    'cache_ttl' => (int) env('SETTINGS_CACHE_TTL', 60),
];
