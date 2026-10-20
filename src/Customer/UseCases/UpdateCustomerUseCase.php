<?php

namespace Src\Customer\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Customer\Models\Customer;

/**
 * Edición de cliente: actualiza el agregado y, si se toca algún dato de contacto
 * del respaldo, la persona o la empresa.
 *
 * El documento no se edita (`UpdateCustomerRequest` no lo acepta). `display_name`
 * se recalcula desde los nombres cuando no viene explícito, para que el listado
 * no muestre un nombre viejo tras un cambio de nombre.
 */
class UpdateCustomerUseCase
{
    /** Campos que vive en el propio agregado. */
    private const CUSTOMER_FIELDS = ['display_name', 'email', 'phone', 'address', 'credit_limit'];

    /** Campos del respaldo persona. */
    private const PERSON_FIELDS = ['first_name', 'last_name', 'email', 'phone'];

    /** Campos del respaldo empresa. */
    private const COMPANY_FIELDS = ['business_name', 'trade_name', 'address', 'contact_email', 'contact_phone'];

    public function execute(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data): Customer {
            $isPerson = $customer->customer_type === Customer::TYPE_PERSON;
            $backingFields = array_intersect_key($data, array_flip($isPerson ? self::PERSON_FIELDS : self::COMPANY_FIELDS));

            if ($backingFields !== []) {
                $isPerson
                    ? $customer->person?->update($backingFields)
                    : $customer->company?->update($backingFields);
            }

            $customerFields = array_intersect_key($data, array_flip(self::CUSTOMER_FIELDS));

            if (! array_key_exists('display_name', $customerFields)) {
                $customerFields['display_name'] = $this->displayName($customer);
            }

            $customer->update($customerFields);

            return $customer->refresh();
        });
    }

    /** Nombre a mostrar: el de la persona o la razón social de la empresa. */
    private function displayName(Customer $customer): string
    {
        if ($customer->customer_type !== Customer::TYPE_PERSON) {
            return $customer->company?->business_name ?? $customer->display_name;
        }

        $person = $customer->person;

        return $person !== null
            ? trim("{$person->first_name} {$person->last_name}")
            : $customer->display_name;
    }
}
