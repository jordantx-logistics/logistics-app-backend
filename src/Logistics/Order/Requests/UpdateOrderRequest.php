<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Order\Models\Order;

/**
 * Edición de una orden antes del embarque.
 *
 * No permite cambiar remitente, destinatario, agencias, servicio ni tarifa:
 * esos datos son la identidad comercial de la orden. El precio se recalcula
 * solo cuando cambia algo que lo afecta (peso, volumen o reparto).
 */
class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string'],
            'weight_kg' => ['sometimes', 'numeric', 'gt:0'],
            'volume_m3' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'declared_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'delivery_type' => ['sometimes', 'string', Rule::in(Order::DELIVERY_TYPES)],
            'delivery_location_id' => ['sometimes', 'nullable', 'integer', 'required_if:delivery_type,home_delivery'],
            'recipient_address' => ['sometimes', 'nullable', 'string', 'max:255', 'required_if:delivery_type,home_delivery'],
            'delivery_scheduled_at' => ['sometimes', 'nullable', 'date'],
            'requires_signature' => ['sometimes', 'boolean'],
        ];
    }
}
