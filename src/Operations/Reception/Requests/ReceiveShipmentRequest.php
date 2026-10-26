<?php

namespace Src\Operations\Reception\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.ticket_code' => ['required', 'string', 'max:8', 'distinct'],
            'items.*.scan_id' => ['nullable', 'uuid'],
            'items.*.scanned_at' => ['nullable', 'date'],
        ];
    }
}
