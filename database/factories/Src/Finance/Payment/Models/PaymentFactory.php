<?php

namespace Database\Factories\Src\Finance\Payment\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\Payment\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = $this->faker->randomFloat(2, 10, 500);

        return [
            'ticket_number' => 'PAY-2026-'.$this->faker->unique()->numerify('######'),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('PAY####')),
            'idempotency_key' => $this->faker->unique()->uuid(),
            'order_id' => $this->faker->numberBetween(1, 100),
            'cash_session_id' => CashSession::factory(),
            'agency_id' => $this->faker->numberBetween(1, 20),
            'amount' => $total,
            'currency' => 'PEN',
            'payment_method' => Payment::METHOD_CASH,
            'reference' => null,
            'document_type' => Payment::DOCUMENT_BOLETA,
            'status' => Payment::STATUS_REGISTERED,
            'created_by' => $this->faker->numberBetween(1, 10),
            'paid_at' => now(),
        ];
    }
}
