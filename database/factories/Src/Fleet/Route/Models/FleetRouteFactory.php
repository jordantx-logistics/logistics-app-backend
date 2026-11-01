<?php

namespace Database\Factories\Src\Fleet\Route\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<FleetRoute>
 */
class FleetRouteFactory extends Factory
{
    protected $model = FleetRoute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'origin_agency_id' => Agency::factory(),
            'destination_agency_id' => Agency::factory(),
            'status' => FleetRoute::STATUS_PLANNED,
            'planned_departure_at' => now()->addDay(),
            'planned_arrival_at' => now()->addDays(2),
        ];
    }
}
