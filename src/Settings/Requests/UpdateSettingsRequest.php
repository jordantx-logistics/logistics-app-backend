<?php

namespace Src\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Las claves llevan puntos, así que viajan como lista y no como objeto: la
     * validación de Laravel leería `fleet.per_diem_max` como un anidamiento.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'distinct'],
            'settings.*.value' => ['required'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'settings' => ['description' => 'Parameters to change.'],
            'settings.*.key' => ['description' => 'Setting key.', 'example' => 'fleet.per_diem_max'],
            'settings.*.value' => ['description' => 'New value.', 'example' => '600.00'],
        ];
    }
}
