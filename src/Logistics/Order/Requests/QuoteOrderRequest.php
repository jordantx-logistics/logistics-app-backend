<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Order\Models\Order;

/**
 * Cotización (UC-04): los mismos datos de ruta y medidas que el alta, sin
 * cliente ni clave de idempotencia.
 */
class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin_agency_id' => ['required', 'integer'],
            'destination_agency_id' => ['required', 'integer'],
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'service_type' => ['nullable', 'string', Rule::in(Order::SERVICE_TYPES)],
            'delivery_type' => ['nullable', 'string', Rule::in(Order::DELIVERY_TYPES)],
            'delivery_location_id' => ['nullable', 'integer', 'required_if:delivery_type,home_delivery'],
        ];
    }
}
