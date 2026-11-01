<?php

namespace Database\Factories\Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Shipment\Models\Disembark;
use Src\Operations\Shipment\Models\Embark;

/**
 * @extends Factory<Disembark>
 */
class DisembarkFactory extends Factory
{
    protected $model = Disembark::class;

    public function definition(): array
    {
        return [
            'agency_id' => $this->faker->numberBetween(1, 20),
            'embark_id' => Embark::factory(),
            'arrived_at' => now(),
        ];
    }
}
