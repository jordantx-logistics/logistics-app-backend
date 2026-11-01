<?php

namespace Src\Finance\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'agency_id' => [$required, 'integer', 'min:1'],
            'document_type' => [$required, 'string', 'in:boleta,factura'],
            'series' => [$required, 'string', 'size:4', 'regex:/^[A-Z0-9]{4}$/'],
            'issuer_ruc' => [$required, 'string', 'digits:11'],
            'issuer_name' => [$required, 'string', 'max:200'],
            'issuer_trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'issuer_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issuer_ubigeo' => ['sometimes', 'nullable', 'string', 'digits:6'],
            'establishment_code' => ['sometimes', 'nullable', 'string', 'size:4'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
