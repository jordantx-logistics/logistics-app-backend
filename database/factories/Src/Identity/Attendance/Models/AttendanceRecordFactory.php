<?php

namespace Database\Factories\Src\Identity\Attendance\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Employee\Models\Employee;

/**
 * `employee_id` es FK intra-Identity hacia `employees`, así que por defecto la
 * factory crea el empleado. `agency_id` es una referencia de Organization sin FK
 * (ADR-0006): aquí es un número suelto.
 *
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'agency_id' => $this->faker->numberBetween(1, 20),
            'type' => AttendanceRecord::TYPE_IN,
            'marked_at' => now(),
            'source' => 'web',
            'device_id' => null,
            'latitude' => null,
            'longitude' => null,
            'ip_address' => $this->faker->ipv4(),
            'status' => AttendanceRecord::STATUS_VALID,
            'idempotency_key' => $this->faker->unique()->uuid(),
        ];
    }
}
