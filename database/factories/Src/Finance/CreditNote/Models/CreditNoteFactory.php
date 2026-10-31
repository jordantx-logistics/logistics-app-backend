<?php

namespace Database\Factories\Src\Finance\CreditNote\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'CRN-2026-'.$this->faker->unique()->numerify('######'),
            'ticket_code' => strtoupper($this->faker->unique()->bothify('CRN####')),
            'order_id' => $this->faker->numberBetween(1, 100),
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'agency_id' => $this->faker->numberBetween(1, 20),
            'cash_session_id' => CashSession::factory(),
            'amount' => $this->faker->randomFloat(2, 1, 300),
            'currency' => 'PEN',
            'reason' => '01',
            'note' => $this->faker->sentence(),
            'status' => CreditNote::STATUS_ACCEPTED,
            'created_by' => $this->faker->numberBetween(1, 10),
            'issued_at' => now(),
            'xml_path' => null,
            'pdf_path' => null,
            'cdr_path' => null,
            'sunat_code' => '0',
            'sunat_message' => 'Aceptado',
        ];
    }
}
