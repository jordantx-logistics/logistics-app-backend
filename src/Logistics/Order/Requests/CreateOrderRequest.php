<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
