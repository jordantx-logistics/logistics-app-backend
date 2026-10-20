<?php

namespace Database\Factories\Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Logistics\Location\Models\Location;
use Src\Logistics\Location\Models\Zone;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'ubigeo' => $this->faker->unique()->numerify('######'),
            'department' => $this->faker->state(),
            'province' => $this->faker->city(),
            'district' => $this->faker->city(),
            'zone_id' => Zone::factory(),
            'is_active' => true,
        ];
    }
}
