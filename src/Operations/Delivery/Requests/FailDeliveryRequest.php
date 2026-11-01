<?php

namespace Src\Operations\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'failure_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
