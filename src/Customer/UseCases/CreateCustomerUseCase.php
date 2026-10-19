<?php

namespace Src\Customer\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Customer\Models\Company;
use Src\Customer\Models\Customer;
use Src\Customer\Models\Person;
use Src\Customer\Rules\ValidateCustomerDocument;
use Src\Customer\Rules\ValidateCustomerUniqueDocument;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta de cliente: crea el agregado **y** su entidad de respaldo.
 *
 * La persona o la empresa se crean en la misma transacción que el cliente. No se
 * admite «vincular una persona existente»: las altas del MVP
 * (`POST /api/v1/customer/persons|companies`) pasan por aquí con el tipo ya
 * fijado, así que todos los caminos crean el agregado y ninguno deja una persona
 * suelta que no se pueda usar como remitente.
 *
 * `createdBy` llega por parámetro (no por constructor): el caso de uso no
 * depende de `App\Models\User` ni de la petición.
 */
class CreateCustomerUseCase
{
    public function execute(array $data, ?int $createdBy = null): Customer
    {
        $data = RulesPipeline::run($data, [
            ValidateCustomerDocument::class,
            ValidateCustomerUniqueDocument::class,
        ]);

        return DB::transaction(function () use ($data, $createdBy): Customer {
            $isCompany = $data['customer_type'] === Customer::TYPE_COMPANY;

            $person = $isCompany ? null : Person::create([
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $company = $isCompany ? Company::create([
                'tax_id' => $data['document_number'],
                'business_name' => $data['business_name'],
                'trade_name' => $data['trade_name'] ?? null,
                'address' => $data['address'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]) : null;

            return Customer::create([
                'customer_type' => $data['customer_type'],
                'person_id' => $person?->id,
                'company_id' => $company?->id,
                'display_name' => $data['display_name']
                    ?? ($person !== null ? trim("{$person->first_name} {$person->last_name}") : $company->business_name),
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'],
                // El email del cliente cae al del respaldo cuando no se indica:
                // es el que usa el correo de confirmación de la orden.
                'email' => $data['email'] ?? $data['contact_email'] ?? null,
                'phone' => $data['phone'] ?? $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'credit_limit' => $data['credit_limit'] ?? null,
                'created_by' => $createdBy,
            ]);
        });
    }
}
