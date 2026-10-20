<?php

namespace Database\Factories\Src\Customer\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Customer\Models\Company;
use Src\Customer\Models\Customer;
use Src\Customer\Models\Person;

/**
 * Cliente de pruebas.
 *
 * Por defecto es una persona: crea la `Person` de respaldo y **copia** sus datos
 * al agregado, que es como lo hace `CreateCustomerUseCase`. Con `forCompany()`
 * el respaldo es una empresa.
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $person = Person::factory()->create();

        return [
            'customer_type' => Customer::TYPE_PERSON,
            'person_id' => $person->id,
            'company_id' => null,
            'display_name' => trim($person->first_name.' '.$person->last_name),
            'document_type' => $person->document_type,
            'document_number' => $person->document_number,
            'email' => $person->email,
            'phone' => $person->phone,
            'address' => null,
            'is_active' => true,
            'credit_limit' => null,
        ];
    }

    /** Cliente respaldado por una empresa. */
    public function forCompany(?Company $company = null): static
    {
        $company ??= Company::factory()->create();

        return $this->state(fn (): array => [
            'customer_type' => Customer::TYPE_COMPANY,
            'person_id' => null,
            'company_id' => $company->id,
            'display_name' => $company->business_name,
            'document_type' => 'RUC',
            'document_number' => $company->tax_id,
            'email' => $company->contact_email,
            'phone' => $company->contact_phone,
            'address' => $company->address,
        ]);
    }

    /** Cliente inactivo (no se borra: se desactiva). */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
