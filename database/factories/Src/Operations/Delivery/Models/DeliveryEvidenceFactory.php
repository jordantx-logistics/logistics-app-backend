<?php

namespace Database\Factories\Src\Operations\Delivery\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Operations\Delivery\Models\DeliveryEvidence;

/**
 * @extends Factory<DeliveryEvidence>
 */
class DeliveryEvidenceFactory extends Factory
{
    protected $model = DeliveryEvidence::class;

    public function definition(): array
    {
        return [
            'delivery_id' => $this->faker->numberBetween(1, 100),
            'signature_path' => 'deliveries/signature-'.$this->faker->numerify('###').'.png',
            'photo_path' => 'deliveries/photo-'.$this->faker->numerify('###').'.jpg',
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'captured_at' => now(),
        ];
    }
}
