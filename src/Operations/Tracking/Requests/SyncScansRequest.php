<?php

namespace Src\Operations\Tracking\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Operations\Tracking\Models\Scan;

class SyncScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['nullable', 'string', 'max:64'],
            'scans' => ['required', 'array', 'min:1', 'max:500'],
            'scans.*.scan_id' => ['required', 'uuid'],
            'scans.*.ticket_code' => ['required', 'string', 'max:8'],
            'scans.*.scan_type' => ['required', 'string', Rule::in(Scan::TYPES)],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.embark_id' => ['nullable', 'integer', 'min:1'],
            'scans.*.agency_id' => ['nullable', 'integer', 'min:1'],
            'scans.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'scans.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
