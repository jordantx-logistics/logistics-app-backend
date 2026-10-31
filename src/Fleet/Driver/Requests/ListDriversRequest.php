<?php

namespace Src\Fleet\Driver\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Driver\Models\Driver;

class ListDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** En una query string llega como texto (`true`), que la regla `boolean` rechaza. */
    protected function prepareForValidation(): void
    {
        if ($this->has('available')) {
            $this->merge(['available' => $this->boolean('available')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(Driver::STATUSES)],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'available' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
