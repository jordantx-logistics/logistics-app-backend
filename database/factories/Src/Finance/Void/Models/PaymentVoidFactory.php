<?php

namespace Database\Factories\Src\Finance\Void\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;
use Src\Finance\Void\Models\PaymentVoid;

/**
 * @extends Factory<PaymentVoid>
 */
class PaymentVoidFactory extends Factory
{
    protected $model = PaymentVoid::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => $this->faker->numberBetween(1, 100),
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'reason' => $this->faker->sentence(),
            'status' => PaymentVoid::STATUS_REQUESTED,
            'requested_by' => User::factory(),
            'approved_by' => null,
            'requested_at' => now(),
            'approved_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];
    }
}
