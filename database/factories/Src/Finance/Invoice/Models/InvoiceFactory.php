<?php

namespace Database\Factories\Src\Finance\Invoice\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Finance\Payment\Models\Payment;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = $this->faker->randomFloat(2, 10, 500);
        $taxable = round($total / 1.18, 2);
        $number = $this->faker->unique()->numberBetween(1, 999_999);

        return [
            'ticket_number' => sprintf('LIM01-B001-%06d', $number),
            'order_id' => $this->faker->numberBetween(1, 100),
            'payment_id' => Payment::factory(),
            'agency_id' => $this->faker->numberBetween(1, 20),
            'invoice_series_id' => InvoiceSeries::factory(),
            'document_type' => Invoice::DOCUMENT_BOLETA,
            'series' => 'B001',
            'number' => $number,
            'customer_document_type' => '1',
            'customer_document_number' => $this->faker->numerify('########'),
            'customer_name' => $this->faker->name(),
            'taxable_amount' => $taxable,
            'igv' => round($total - $taxable, 2),
            'total' => $total,
            'currency' => 'PEN',
            'status' => Invoice::STATUS_ACCEPTED,
            'xml_path' => null,
            'pdf_path' => null,
            'cdr_path' => null,
            'sunat_code' => '0',
            'sunat_message' => 'Aceptado',
            'issued_at' => now(),
            'accepted_at' => now(),
        ];
    }
}
