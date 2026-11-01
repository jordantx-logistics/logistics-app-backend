<?php

namespace Src\Reporting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Reporting\Models\ReportExport;

class StoreReportExportRequest extends FormRequest
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
        return [
            'report' => ['required', 'string', 'max:50'],
            'format' => ['required', 'string', Rule::in(ReportExport::FORMATS)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'agency_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'report' => ['description' => 'Report key from the catalog.', 'example' => 'orders'],
            'format' => ['description' => '`csv`, `xlsx` or `pdf`.', 'example' => 'csv'],
            'from' => ['description' => 'First day (inclusive).', 'example' => '2026-10-01'],
            'to' => ['description' => 'Last day (inclusive).', 'example' => '2026-10-31'],
            'agency_id' => ['description' => 'One agency; without it, your whole scope.', 'example' => 1],
            'status' => ['description' => 'One of the statuses the report accepts.', 'example' => null],
        ];
    }
}
