<?php

namespace Src\Operations\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinishInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:5000'],
            'items.*.shipment_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.found' => ['required', 'boolean'],
            'items.*.motive' => ['nullable', 'string', 'max:255'],
        ];
    }
}
