<?php

namespace Src\Shared\DTOs;

/**
 * Tarifario resuelto, listo para calcular (contexto `Logistics/Order`).
 *
 * Es el contrato entre el contexto `Tariff` y el motor de precios: un objeto
 * plano, sin Eloquent, para que el cálculo sea determinista y testeable sin
 * base de datos (solo se construye en el adaptador `EloquentTariffDirectory`).
 *
 * Se devuelve tal cual desde la caché (`Cache::tags('tariffs')`), así que no
 * debe llevar modelos ni closures.
 */
final class ResolvedTariff
{
    /**
     * @param  list<array{from_kg: float, to_kg: float, fixed_fee: float, price_per_kg: float}>  $brackets
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $serviceType,
        public readonly string $scopeType,
        public readonly float $basePrice,
        public readonly float $pricePerKg,
        public readonly float $pricePerM3,
        public readonly float $minCharge,
        public readonly float $maxWeightKg,
        public readonly int $volumetricFactor,
        public readonly array $brackets = [],
    ) {}
}
