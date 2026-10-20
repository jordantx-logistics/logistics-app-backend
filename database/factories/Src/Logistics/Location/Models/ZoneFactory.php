<?php

namespace Database\Factories\Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Logistics\Location\Models\Zone;

class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    public function definition(): array
    {
        return [
            'code' => 'ZON-'.$this->faker->unique()->numerify('###'),
            'name' => $this->faker->state(),
            'is_active' => true,
        ];
    }
}
