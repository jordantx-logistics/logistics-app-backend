<?php

namespace Src\Operations\Reception\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListReceptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency' => ['sometimes', 'integer', 'min:1'],
            'received_from' => ['sometimes', 'date'],
            'received_to' => ['sometimes', 'date', 'after_or_equal:received_from'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
