<?php

namespace Database\Factories\Src\Reporting\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Src\Reporting\Models\ReportExport;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'report' => 'orders',
            'format' => 'csv',
            'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'agency_ids' => null, 'status' => null],
            'status' => ReportExport::STATUS_PENDING,
        ];
    }
}
