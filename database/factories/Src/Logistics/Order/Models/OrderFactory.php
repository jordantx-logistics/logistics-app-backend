<?php

namespace Database\Factories\Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Logistics\Order\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * `ticket_number`/`ticket_code` los pone `HasTicketIdentifiers`; las
     * referencias a otros contextos son columnas sin FK (ADR-0006), así que
     * aquí son números sueltos.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'idempotency_key' => $this->faker->unique()->uuid(),
            'sender_id' => $this->faker->numberBetween(1, 50),
            'receiver_id' => $this->faker->numberBetween(1, 50),
            'origin_agency_id' => $this->faker->numberBetween(1, 20),
            'destination_agency_id' => $this->faker->numberBetween(1, 20),
            'description' => $this->faker->sentence(),
            'weight_kg' => $this->faker->randomFloat(3, 0.5, 500),
            'volume_m3' => $this->faker->randomFloat(4, 0.01, 10),
            'declared_value' => $this->faker->randomFloat(2, 10, 5000),
            'total_amount' => $this->faker->randomFloat(2, 10, 600),
            'status' => 'pending',
            'created_by' => $this->faker->numberBetween(1, 10),
        ];
    }
}
