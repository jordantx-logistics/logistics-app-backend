<?php

namespace Src\Customer\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Customer\Models\Customer;

/**
 * Alta de cliente (agregado `customers`).
 *
 * Solo valida **forma**: el formato del documento y su unicidad son reglas de
 * negocio (`ValidateCustomerDocument`, `ValidateCustomerUniqueDocument`) y
 * devuelven `error_code`. Aquí no se aceptan `person_id`/`company_id`: el alta
 * crea siempre su entidad de respaldo a partir de los datos del payload, así que
 * no hay dos caminos para el mismo registro.
 */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isPerson = $this->input('customer_type') === Customer::TYPE_PERSON;

        return [
            'customer_type' => ['required', 'string', Rule::in([Customer::TYPE_PERSON, Customer::TYPE_COMPANY])],
            'document_type' => ['required', 'string', 'max:10'],
            'document_number' => ['required', 'string', 'max:20'],
            'display_name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],

            // Respaldo persona
            'first_name' => [Rule::requiredIf($isPerson), 'string', 'max:100'],
            'last_name' => [Rule::requiredIf($isPerson), 'string', 'max:100'],

            // Respaldo empresa
            'business_name' => [Rule::requiredIf(! $isPerson), 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
