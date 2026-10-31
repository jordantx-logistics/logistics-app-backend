<?php

namespace Database\Factories\Src\Finance\Cash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Cash\Models\CashSession;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'cashier_id' => User::factory(),
            'status' => CashSession::STATUS_OPEN,
            'opening_amount' => $this->faker->randomFloat(2, 0, 300),
            'expected_amount' => $this->faker->randomFloat(2, 0, 800),
            'closing_amount' => null,
            'difference' => null,
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }
}
