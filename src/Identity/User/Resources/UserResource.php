<?php

namespace Src\Identity\User\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Usuario administrado por Identity (lectura de gestión, no el perfil propio:
 * ese es `MeResource`).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'employee_id' => $user->employee_id,
            'must_change_password' => (bool) $user->must_change_password,
            'failed_login_attempts' => (int) $user->failed_login_attempts,
            'locked_until' => $user->locked_until?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'roles' => $user->getRoleNames()->values()->all(),
            'agencies' => $user->agencies->map(fn ($agency): array => [
                'id' => $agency->id,
                'name' => $agency->name,
                'is_primary' => (bool) $agency->pivot->is_primary,
            ])->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
