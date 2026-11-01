<?php

namespace Src\Operations\Shipment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisembarkShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'min:1'],
            'arrived_at' => ['nullable', 'date'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.ticket_code' => ['required', 'string', 'max:8', 'distinct'],
            'items.*.scan_id' => ['nullable', 'uuid'],
        ];
    }
}
