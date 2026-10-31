<?php

namespace Database\Factories\Src\Finance\Invoice\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Organization\Models\Agency;

/**
 * @extends Factory<InvoiceSeries>
 */
class InvoiceSeriesFactory extends Factory
{
    protected $model = InvoiceSeries::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'document_type' => 'boleta',
            'series' => strtoupper($this->faker->bothify('B###')),
            'next_number' => 1,
            'is_active' => true,
            'is_default' => true,
            'issuer_ruc' => '20123456789',
            'issuer_name' => 'Logistics Demo SAC',
            'issuer_trade_name' => 'Logistics Demo',
            'issuer_address' => 'Av. Demo 123, Lima',
            'issuer_ubigeo' => '150101',
            'establishment_code' => '0000',
        ];
    }
}
