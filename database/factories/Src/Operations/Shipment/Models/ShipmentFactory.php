<?php

namespace Database\Factories\Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Shipment\Models\Shipment;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'order_id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'ticket_number' => 'ORD-2026-'.$this->faker->unique()->numerify('######'),
            'origin_agency_id' => $this->faker->numberBetween(1, 20),
            'destination_agency_id' => $this->faker->numberBetween(1, 20),
            'current_location_agency_id' => $this->faker->numberBetween(1, 20),
            'status' => Shipment::STATUS_CREATED,
        ];
    }
}
