<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Order\Models\Order;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'sender_id' => ['required', 'integer'],
            'receiver_id' => ['required', 'integer'],
            'origin_agency_id' => ['required', 'integer'],
            'destination_agency_id' => ['required', 'integer'],
            'description' => ['nullable', 'string'],
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],

            'service_type' => ['nullable', 'string', Rule::in(Order::SERVICE_TYPES)],
            'payment_responsibility' => ['nullable', 'string', Rule::in(Order::PAYMENT_RESPONSIBILITIES)],

            'delivery_type' => ['nullable', 'string', Rule::in(Order::DELIVERY_TYPES)],
            'delivery_location_id' => ['nullable', 'integer', 'required_if:delivery_type,home_delivery'],
            'recipient_address' => ['nullable', 'string', 'max:255'],
            'delivery_scheduled_at' => ['nullable', 'date'],
            'requires_signature' => ['boolean'],
        ];
    }
}
