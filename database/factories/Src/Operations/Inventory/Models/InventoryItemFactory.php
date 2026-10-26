<?php

namespace Database\Factories\Src\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Inventory\Models\InventoryItem;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'inventory_count_id' => $this->faker->numberBetween(1, 100),
            'shipment_id' => $this->faker->numberBetween(1, 1_000_000),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'expected' => true,
            'found' => true,
            'difference' => 0,
            'motive' => null,
        ];
    }
}
