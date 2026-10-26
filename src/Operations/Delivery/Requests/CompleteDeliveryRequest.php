<?php

namespace Src\Operations\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_document' => ['required', 'string', 'max:20'],
            'signature_path' => ['required', 'string', 'max:255', 'starts_with:deliveries/'],
            'photo_path' => ['required', 'string', 'max:255', 'starts_with:deliveries/'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'captured_at' => ['required', 'date'],
        ];
    }
}
