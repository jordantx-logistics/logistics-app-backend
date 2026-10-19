<?php

namespace Src\Customer\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de cliente.
 *
 * El documento (`document_type`/`document_number`) **no se edita**: identifica al
 * cliente y cambiarlo sería otro cliente (con su propia unicidad y su propio
 * respaldo). Todo lo demás —contacto, dirección, límite de crédito y los datos
 * de la entidad de respaldo que apliquen— sí.
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Respaldo persona
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],

            // Respaldo empresa
            'business_name' => ['sometimes', 'string', 'max:200'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
