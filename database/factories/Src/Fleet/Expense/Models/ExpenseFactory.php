<?php

namespace Database\Factories\Src\Fleet\Expense\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_id' => FleetRoute::factory(),
            'type' => Expense::TYPE_FUEL,
            'amount' => '380.00',
            'currency' => 'PEN',
            'liters' => '95.00',
            'odometer_km' => '153600.00',
            'status' => Expense::STATUS_REGISTERED,
            'registered_by' => 1,
            'registered_at' => now(),
        ];
    }
}
