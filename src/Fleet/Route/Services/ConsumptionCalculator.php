<?php

namespace Src\Fleet\Route\Services;

/**
 * Consumo y coste por kilómetro. Es un cálculo puro: dado lo mismo, devuelve lo
 * mismo, sin base de datos ni configuración (criterio 4 de la Fase 6).
 */
final class ConsumptionCalculator
{
    /**
     * Rendimiento en km/L, o `null` si faltan datos para calcularlo.
     */
    public function kmPerLiter(float $km, float $liters): ?float
    {
        if ($km <= 0 || $liters <= 0) {
            return null;
        }

        return round($km / $liters, 2);
    }

    /**
     * Coste por kilómetro, o `null` si la ruta no recorrió distancia.
     */
    public function costPerKm(float $km, float $cost): ?float
    {
        return $km > 0 ? round($cost / $km, 2) : null;
    }

    /**
     * ¿El rendimiento está fuera del rango normal? Sin rendimiento no hay anomalía.
     *
     * @param  array{min: float|int, max: float|int}|null  $range
     */
    public function isAnomalous(?float $kmPerLiter, ?array $range): bool
    {
        if ($kmPerLiter === null || $range === null) {
            return false;
        }

        return $kmPerLiter < $range['min'] || $kmPerLiter > $range['max'];
    }
}
