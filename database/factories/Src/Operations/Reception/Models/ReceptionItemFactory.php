<?php

namespace Database\Factories\Src\Operations\Reception\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Reception\Models\ReceptionItem;

/**
 * @extends Factory<ReceptionItem>
 */
class ReceptionItemFactory extends Factory
{
    protected $model = ReceptionItem::class;

    public function definition(): array
    {
        return [
            'reception_id' => $this->faker->numberBetween(1, 100),
            'shipment_id' => $this->faker->numberBetween(1, 1_000_000),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'scan_id' => null,
        ];
    }
}
