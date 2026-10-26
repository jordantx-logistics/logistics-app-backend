<?php

namespace Src\Operations\Shipment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListEmbarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency' => ['sometimes', 'integer', 'min:1'],
            'departed_from' => ['sometimes', 'date'],
            'departed_to' => ['sometimes', 'date', 'after_or_equal:departed_from'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
