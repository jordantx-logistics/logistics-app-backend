<?php

namespace Database\Factories\Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Logistics\Location\Models\AgencyCoverage;
use Src\Logistics\Location\Models\Location;

class AgencyCoverageFactory extends Factory
{
    protected $model = AgencyCoverage::class;

    public function definition(): array
    {
        return [
            'agency_id' => $this->faker->numberBetween(1, 50),
            'location_id' => Location::factory(),
            'allows_pickup' => true,
            'allows_delivery' => false,
            'delivery_surcharge' => 0,
            'is_active' => true,
        ];
    }
}
