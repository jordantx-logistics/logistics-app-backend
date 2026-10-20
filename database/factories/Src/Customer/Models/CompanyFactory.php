<?php

namespace Database\Factories\Src\Customer\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Customer\Models\Company;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            // RUC peruano: 11 dígitos que empiezan por 20 en las personas jurídicas.
            'tax_id' => $this->faker->unique()->numerify('20#########'),
            'business_name' => $this->faker->company().' S.A.C.',
            'trade_name' => $this->faker->company(),
            'address' => $this->faker->address(),
            'contact_email' => $this->faker->unique()->companyEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
        ];
    }
}
