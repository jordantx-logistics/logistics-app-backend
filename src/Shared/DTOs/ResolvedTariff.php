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

    /**
     * Representación plana para cachear. Se guarda un array y no el objeto:
     * `unserialize()` no siempre carga la clase del DTO (según el worker y el
     * orden de autoload), y entonces la caché devolvía `__PHP_Incomplete_Class`
     * y cada petición volvía a la base de datos.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'service_type' => $this->serviceType,
            'scope_type' => $this->scopeType,
            'base_price' => $this->basePrice,
            'price_per_kg' => $this->pricePerKg,
            'price_per_m3' => $this->pricePerM3,
            'min_charge' => $this->minCharge,
            'max_weight_kg' => $this->maxWeightKg,
            'volumetric_factor' => $this->volumetricFactor,
            'brackets' => $this->brackets,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            code: (string) $data['code'],
            name: (string) $data['name'],
            serviceType: (string) $data['service_type'],
            scopeType: (string) $data['scope_type'],
            basePrice: (float) $data['base_price'],
            pricePerKg: (float) $data['price_per_kg'],
            pricePerM3: (float) $data['price_per_m3'],
            minCharge: (float) $data['min_charge'],
            maxWeightKg: (float) $data['max_weight_kg'],
            volumetricFactor: (int) $data['volumetric_factor'],
            brackets: array_map(static fn (array $bracket): array => [
                'from_kg' => (float) $bracket['from_kg'],
                'to_kg' => (float) $bracket['to_kg'],
                'fixed_fee' => (float) $bracket['fixed_fee'],
                'price_per_kg' => (float) $bracket['price_per_kg'],
            ], $data['brackets'] ?? []),
        );
    }
}
