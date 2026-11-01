<?php

namespace Src\Operations\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'min:1'],
            'courier_id' => ['required', 'integer', 'min:1'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shipment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
