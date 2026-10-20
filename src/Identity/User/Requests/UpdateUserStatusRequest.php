<?php

namespace Src\Identity\User\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suspender / reactivar / dar de baja a un usuario (UC-06).
 *
 * El spec solo documenta `DELETE` (suspender); la reactivación de la máquina de
 * estados (`suspended → active`) llega por `PATCH .../status`.
 */
class UpdateUserStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(User::STATUSES)],
        ];
    }
}
