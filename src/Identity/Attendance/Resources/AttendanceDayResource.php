<?php

namespace Src\Identity\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del consolidado diario. `attendance_days` no tiene modelo (clave natural
 * compuesta y sin `id`), así que el recurso envuelve las filas del query builder.
 */
class AttendanceDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => (int) $this->employee_id,
            'work_date' => $this->work_date,
            'first_in_at' => $this->first_in_at,
            'last_out_at' => $this->last_out_at,
            'worked_minutes' => (int) $this->worked_minutes,
            'late_minutes' => (int) $this->late_minutes,
            'status' => $this->status,
        ];
    }
}
