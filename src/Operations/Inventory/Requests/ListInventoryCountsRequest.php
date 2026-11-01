<?php

namespace Src\Operations\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'finished'])],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
