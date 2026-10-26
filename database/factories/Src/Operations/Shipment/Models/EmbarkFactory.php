<?php

namespace Database\Factories\Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Shipment\Models\Embark;

/**
 * @extends Factory<Embark>
 */
class EmbarkFactory extends Factory
{
    protected $model = Embark::class;

    public function definition(): array
    {
        return [
            'agency_id' => $this->faker->numberBetween(1, 20),
            'vehicle_id' => $this->faker->numberBetween(1, 50),
            'driver_id' => $this->faker->numberBetween(1, 50),
            'route_ref' => 'RUTA-'.$this->faker->numerify('###'),
            'departed_at' => now(),
        ];
    }
}
