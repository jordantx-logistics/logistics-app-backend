<?php

namespace Database\Factories\Src\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Inventory\Models\InventoryCount;

/**
 * @extends Factory<InventoryCount>
 */
class InventoryCountFactory extends Factory
{
    protected $model = InventoryCount::class;

    public function definition(): array
    {
        return [
            'agency_id' => $this->faker->numberBetween(1, 20),
            'status' => InventoryCount::STATUS_OPEN,
            'counted_by' => $this->faker->numberBetween(1, 20),
            'finished_at' => null,
        ];
    }
}
