<?php

namespace Database\Factories\Src\Finance\Cash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Cash\Models\CashClosure;
use Src\Finance\Cash\Models\CashSession;

/**
 * @extends Factory<CashClosure>
 */
class CashClosureFactory extends Factory
{
    protected $model = CashClosure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_session_id' => CashSession::factory(),
            'status' => CashClosure::STATUS_APPROVED,
            'expected_amount' => 300,
            'closing_amount' => 300,
            'difference' => 0,
            'requested_by' => User::factory(),
            'approved_by' => User::factory(),
            'requested_at' => now(),
            'approved_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
            'report_path' => null,
        ];
    }
}
