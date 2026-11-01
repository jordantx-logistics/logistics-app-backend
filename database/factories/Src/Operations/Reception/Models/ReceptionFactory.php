<?php

namespace Database\Factories\Src\Operations\Reception\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Reception\Models\Reception;

/**
 * @extends Factory<Reception>
 */
class ReceptionFactory extends Factory
{
    protected $model = Reception::class;

    public function definition(): array
    {
        return [
            'agency_id' => $this->faker->numberBetween(1, 20),
            'received_by' => $this->faker->numberBetween(1, 20),
            'received_at' => now(),
            'status' => 'completed',
        ];
    }
}
