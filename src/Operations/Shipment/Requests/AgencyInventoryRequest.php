<?php

namespace Src\Operations\Shipment\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Src\Operations\Shipment\Models\Shipment;

class AgencyInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'max:120'],
            'search' => ['sometimes', 'string', 'max:20'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('status')) {
                    return;
                }

                foreach (explode(',', (string) $this->input('status')) as $status) {
                    if (! array_key_exists(trim($status), Shipment::TRANSITIONS)) {
                        $validator->errors()->add('status', 'The selected status is invalid.');

                        return;
                    }
                }
            },
        ];
    }
}
