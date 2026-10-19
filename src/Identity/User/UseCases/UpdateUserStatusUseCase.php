<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Src\Identity\User\Rules\ValidateLastAdmin;
use Src\Identity\User\Rules\ValidateUserStatusTransition;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Suspender / reactivar / dar de baja a un usuario (UC-06).
 *
 * Valida la transición de la máquina de estados y que no se quede el sistema sin
 * `admin` activo (`LAST_ADMIN_PROTECTED`). Al salir de `active`, revoca tokens y
 * sesiones: el usuario deja de operar de inmediato.
 *
 * No emite `user.deactivated`/`user.activated` (sin consumidor todavía).
 */
class UpdateUserStatusUseCase
{
    public function execute(User $user, string $status): User
    {
        RulesPipeline::run(
            ['user' => $user, 'status' => $status],
            [
                ValidateUserStatusTransition::class,
                ValidateLastAdmin::class,
            ],
        );

        return DB::transaction(function () use ($user, $status): User {
            $user->forceFill(['status' => $status])->save();

            if ($status !== User::STATUS_ACTIVE) {
                $user->tokens()->delete();
            }

            return $user;
        });
    }
}
