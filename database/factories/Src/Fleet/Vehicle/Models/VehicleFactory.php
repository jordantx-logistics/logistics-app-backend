<?php

namespace Database\Factories\Src\Fleet\Vehicle\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plate' => strtoupper($this->faker->unique()->bothify('???###')),
            'type' => Vehicle::TYPE_TRUCK,
            'capacity_kg' => '12000.00',
            'capacity_m3' => '45.00',
            'owner_agency_id' => Agency::factory(),
            'status' => Vehicle::STATUS_AVAILABLE,
            'odometer_km' => '100000.00',
        ];
    }
}
