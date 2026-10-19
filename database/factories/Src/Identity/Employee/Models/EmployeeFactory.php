<?php

namespace Database\Factories\Src\Identity\Employee\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Identity\Employee\Models\Employee;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => null,
            'document_type' => 'DNI',
            'document_number' => $this->faker->unique()->numerify('########'),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('9########'),
            'position' => $this->faker->randomElement(['counter', 'cashier', 'driver', 'warehouse']),
            // `agencies` es de otro contexto: la factory solo pone un id suelto
            // (ADR-0006); el test crea la agencia si la regla la necesita.
            'agency_id' => $this->faker->numberBetween(1, 20),
            'hired_at' => $this->faker->dateTimeBetween('-3 years')->format('Y-m-d'),
            'terminated_at' => null,
            'status' => Employee::STATUS_ACTIVE,
        ];
    }
}
