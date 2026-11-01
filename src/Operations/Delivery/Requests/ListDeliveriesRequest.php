<?php

namespace Src\Operations\Delivery\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Src\Operations\Delivery\Models\Delivery;

class ListDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:120'],
            'courier_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_code' => ['sometimes', 'string', 'max:8'],
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

                $valid = [
                    Delivery::STATUS_ASSIGNED,
                    Delivery::STATUS_IN_PROGRESS,
                    Delivery::STATUS_DELIVERED,
                    Delivery::STATUS_FAILED,
                    Delivery::STATUS_RETURNED,
                ];

                foreach (explode(',', (string) $this->input('status')) as $status) {
                    if (! in_array(trim($status), $valid, true)) {
                        $validator->errors()->add('status', 'The selected status is invalid.');

                        return;
                    }
                }
            },
        ];
    }
}
