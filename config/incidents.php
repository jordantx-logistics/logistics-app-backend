<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plazo de atención por tipo (horas)
    |--------------------------------------------------------------------------
    |
    | Valores iniciales provisionales: falta confirmarlos con la operación.
    |
    */
    'sla_hours' => [
        'damage' => (int) env('INCIDENT_SLA_DAMAGE_HOURS', 48),
        'loss' => (int) env('INCIDENT_SLA_LOSS_HOURS', 72),
        'delay' => (int) env('INCIDENT_SLA_DELAY_HOURS', 24),
        'billing' => (int) env('INCIDENT_SLA_BILLING_HOURS', 48),
        'other' => (int) env('INCIDENT_SLA_OTHER_HOURS', 72),
    ],
];
