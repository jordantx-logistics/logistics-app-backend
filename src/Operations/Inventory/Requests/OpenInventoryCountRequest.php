<?php

namespace Src\Operations\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
