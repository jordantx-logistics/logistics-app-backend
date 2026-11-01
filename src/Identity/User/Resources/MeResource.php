<?php

namespace Src\Identity\User\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Shared\Contracts\EmployeeDirectory;

/**
 * Usuario autenticado con su contexto de autorización: lo que el frontend
 * necesita para condicionar la UI (`can()`), nunca para decidir de verdad
 * (`spec/02-objetivo/04-autorizacion.md`, contrato con el frontend).
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'agencies' => $user->agencyIds(),
            // La app corporativa marca por empleado y la de reparto pide sus entregas.
            'employee_id' => $user->employee_id,
            'employee_agency_id' => $user->employee_id === null
                ? null
                : app(EmployeeDirectory::class)->employeeAgencyId((int) $user->employee_id),
        ];
    }
}
