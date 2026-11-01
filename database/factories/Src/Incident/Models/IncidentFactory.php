<?php

namespace Database\Factories\Src\Incident\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Incident\Models\Incident;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => 1,
            'ticket_code' => 'AB12CD34',
            'origin_agency_id' => 1,
            'destination_agency_id' => 2,
            'type' => 'damage',
            'priority' => 'normal',
            'status' => Incident::STATUS_OPEN,
            'description' => 'Box arrived crushed.',
            'assigned_to' => null,
            'due_at' => now()->addHours(48),
            'created_by' => 1,
        ];
    }
}
