<?php

namespace Database\Factories\Src\Organization\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('???##')),
            'name' => $this->faker->company(),
            'address' => $this->faker->streetAddress(),
            'phone' => $this->faker->phoneNumber(),
            'district' => $this->faker->city(),
            'is_active' => true,
        ];
    }
}
