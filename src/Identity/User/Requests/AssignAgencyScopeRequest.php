<?php

namespace Src\Identity\User\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sincroniza el ámbito de agencia de un usuario (UC-16).
 *
 * `agency_ids` se manda completo (`present`): lo que no venga, se revoca. Si no
 * se indica `primary_agency_id`, la primera agencia pasa a ser la principal.
 */
class AssignAgencyScopeRequest extends FormRequest
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
            'agency_ids' => ['present', 'array'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')],
            'primary_agency_id' => ['nullable', 'integer'],
        ];
    }
}
