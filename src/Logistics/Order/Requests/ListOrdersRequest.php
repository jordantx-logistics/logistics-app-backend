<?php

namespace Src\Logistics\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Order\Models\Order;

/**
 * Filtros del listado de órdenes.
 *
 * Los filtros por documento se resuelven con `CustomerDirectory` en el caso de
 * uso; aquí solo se valida la forma. `created_from` y `created_to` son fechas
 * ISO 8601 y se aplican por día completo en la zona de la aplicación.
 */
class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                $statuses = is_array($value) ? $value : explode(',', (string) $value);

                foreach ($statuses as $status) {
                    if (! is_string($status) || ! array_key_exists(trim($status), Order::TRANSITIONS)) {
                        $fail("The selected {$attribute} is invalid.");

                        return;
                    }
                }
            }],

            'origin_agency_id' => ['sometimes', 'integer', 'min:1'],
            'destination_agency_id' => ['sometimes', 'integer', 'min:1'],
            'agency' => ['sometimes', 'integer', 'min:1'],

            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],

            'sender_id' => ['sometimes', 'integer', 'min:1'],
            'receiver_id' => ['sometimes', 'integer', 'min:1'],

            'sender_document' => ['sometimes', 'string', 'max:20'],
            'sender_document_type' => ['sometimes', 'string', Rule::in(['DNI', 'RUC', 'CE', 'PASSPORT'])],
            'receiver_document' => ['sometimes', 'string', 'max:20'],
            'receiver_document_type' => ['sometimes', 'string', Rule::in(['DNI', 'RUC', 'CE', 'PASSPORT'])],

            'ticket_code' => ['sometimes', 'string', 'max:8'],
            'ticket_number' => ['sometimes', 'string', 'max:20'],
            'ticket' => ['sometimes', 'string', 'max:20'],

            // El tope y el mínimo los aplica el caso de uso: un `per_page` fuera
            // de rango se recorta, no se rechaza (comportamiento previo).
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
