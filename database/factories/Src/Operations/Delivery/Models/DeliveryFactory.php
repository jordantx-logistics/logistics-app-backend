<?php

namespace Database\Factories\Src\Operations\Delivery\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Delivery\Models\Delivery;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            'shipment_id' => $this->faker->numberBetween(1, 1_000_000),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'ticket_number' => 'ORD-2026-'.$this->faker->unique()->numerify('######'),
            'agency_id' => $this->faker->numberBetween(1, 20),
            'courier_id' => $this->faker->numberBetween(1, 50),
            'status' => Delivery::STATUS_ASSIGNED,
            'attempt' => 1,
        ];
    }
}
