<?php

namespace Database\Factories\Src\Logistics\Tariff\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Logistics\Tariff\Models\Tariff;

class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'code' => 'TAR-'.$this->faker->unique()->numerify('####'),
            'name' => 'Tarifa '.$this->faker->words(2, true),
            'service_type' => Tariff::SERVICE_STANDARD,
            'scope_type' => Tariff::SCOPE_NATIONAL,
            'base_price' => 5,
            'price_per_kg' => 2.5,
            'price_per_m3' => 0,
            'min_charge' => 0,
            'max_weight_kg' => 500,
            'volumetric_factor' => 200,
            'priority' => 0,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => null,
            'is_active' => true,
        ];
    }
}
