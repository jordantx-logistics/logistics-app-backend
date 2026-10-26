<?php

namespace Database\Factories\Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Shipment\Models\EmbarkItem;

/**
 * @extends Factory<EmbarkItem>
 */
class EmbarkItemFactory extends Factory
{
    protected $model = EmbarkItem::class;

    public function definition(): array
    {
        return [
            'embark_id' => $this->faker->numberBetween(1, 100),
            'shipment_id' => $this->faker->numberBetween(1, 1_000_000),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'scan_id' => null,
        ];
    }
}
