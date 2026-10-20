<?php

namespace Src\Identity\User\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            // La plataforma se exige solo cuando se registra el dispositivo
            // (`device_uuid` presente): sin uuid solo se emite el token.
            'platform' => ['nullable', 'string', 'max:50', 'required_with:device_uuid'],
            'device_uuid' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
