<?php

namespace Database\Factories\Src\Finance\Cash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Cash\Models\CashMovement;
use Src\Finance\Cash\Models\CashSession;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_session_id' => CashSession::factory(),
            'type' => CashMovement::TYPE_INCOME,
            'amount' => $this->faker->randomFloat(2, 1, 300),
            'reference_type' => null,
            'reference_id' => null,
            'note' => $this->faker->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
