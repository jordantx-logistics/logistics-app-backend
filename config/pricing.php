<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motor de precios
    |--------------------------------------------------------------------------
    |
    | `tariff` resuelve el precio con los tarifarios de la Fase 3 (alcance
    | nacional / par de zonas / par de ubicaciones, tramos por peso y cobertura
    | de reparto de la agencia destino).
    |
    | `legacy` es el motor del MVP (5.00 + kg × 2.50 + m³ × 50.00) y se queda
    | como **interruptor de rollback**: si los tarifarios no están cargados o dan
    | problemas, se vuelve a él cambiando solo esta variable (y recargando los
    | workers: la configuración se lee al arrancar).
    |
    | El valor por defecto es el motor nuevo; la suite de tests fija `legacy`
    | para seguir comprobando el camino de rollback (ver `phpunit.xml`).
    |
    */

    'engine' => env('PRICING_ENGINE', 'tariff'),

];
