<?php

namespace Database\Factories\Src\Fleet\Driver\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Fleet\Driver\Models\Driver;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'license_number' => strtoupper($this->faker->unique()->bothify('Q########')),
            'license_category' => 'A-III',
            'license_expires_at' => now()->addYear()->toDateString(),
            'phone' => $this->faker->numerify('9########'),
            'status' => Driver::STATUS_ACTIVE,
            'home_agency_id' => Agency::factory(),
        ];
    }
}
