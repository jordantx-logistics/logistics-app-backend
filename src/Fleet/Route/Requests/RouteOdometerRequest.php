<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Lectura de odómetro al iniciar o finalizar una ruta. */
class RouteOdometerRequest extends FormRequest
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
            'odometer_km' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
