<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Order\Models\Order;

class UpdateDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_type' => ['required', 'string', Rule::in(Order::DELIVERY_TYPES)],
            'delivery_location_id' => ['nullable', 'integer', 'required_if:delivery_type,home_delivery'],
            'recipient_address' => ['nullable', 'string', 'max:255', 'required_if:delivery_type,home_delivery'],
            'delivery_scheduled_at' => ['nullable', 'date'],
            'requires_signature' => ['boolean'],
        ];
    }
}
