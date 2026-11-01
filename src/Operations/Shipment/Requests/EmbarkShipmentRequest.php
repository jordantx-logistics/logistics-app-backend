<?php

namespace Src\Operations\Shipment\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class EmbarkShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_id' => ['required', 'integer', 'min:1'],
            'vehicle_id' => ['required', 'integer', 'min:1'],
            'driver_id' => ['required', 'integer', 'min:1'],
            'route_ref' => ['nullable', 'string', 'max:64'],
            'departed_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.ticket_code' => ['required', 'string', 'max:8'],
            'items.*.scan_id' => ['nullable', 'uuid'],
            'items.*.scanned_at' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = $this->input('items');

                if (! is_array($items)) {
                    return;
                }

                $tickets = array_map(
                    fn (mixed $item): string => is_array($item) ? (string) ($item['ticket_code'] ?? '') : '',
                    $items,
                );

                if (count($tickets) !== count(array_unique($tickets))) {
                    $validator->errors()->add('items', 'The items contain duplicated ticket codes.');
                }
            },
        ];
    }
}
