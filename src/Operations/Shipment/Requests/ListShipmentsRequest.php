<?php

namespace Src\Operations\Shipment\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Src\Operations\Shipment\Models\Shipment;

class ListShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'max:120'],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'origin_agency_id' => ['sometimes', 'integer', 'min:1'],
            'destination_agency_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_code' => ['sometimes', 'string', 'max:8'],
            'ticket_number' => ['sometimes', 'string', 'max:20'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateStatuses($validator);
            },
        ];
    }

    private function validateStatuses(Validator $validator): void
    {
        if (! $this->filled('status')) {
            return;
        }

        foreach (explode(',', (string) $this->input('status')) as $status) {
            if (! array_key_exists(trim($status), Shipment::TRANSITIONS)) {
                $validator->errors()->add('status', 'The selected status is invalid.');

                return;
            }
        }
    }
}
