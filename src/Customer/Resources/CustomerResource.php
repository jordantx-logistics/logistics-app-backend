<?php

namespace Src\Customer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Customer\Models\Customer;

/**
 * Cliente del agregado `customers`.
 *
 * Incluye `person_id`/`company_id` solo como referencia: el detalle de la
 * entidad de respaldo (nombres, razón social) ya viene aplanado en
 * `display_name`, que es lo que consumen las órdenes y los listados.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_type' => $this->customer_type,
            'person_id' => $this->person_id,
            'company_id' => $this->company_id,
            'display_name' => $this->display_name,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'credit_limit' => $this->credit_limit,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
