<?php

namespace Src\Identity\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Identity\Attendance\Models\AttendanceRecord;

/**
 * Forma del registro de marcación (UC-17).
 *
 * La idempotencia, la secuencia y el estado del empleado **no** son validación
 * de forma, sino reglas de negocio del `RulesPipeline`. Aquí solo se comprueba
 * la forma del payload.
 */
class RegisterAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'agency_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(AttendanceRecord::TYPES)],
            'marked_at' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(AttendanceRecord::SOURCES)],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
