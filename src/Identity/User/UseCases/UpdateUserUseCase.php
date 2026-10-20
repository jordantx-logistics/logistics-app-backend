<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Arr;
use Src\Identity\User\Rules\ValidateUniqueUserEmail;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Edición de los datos de un usuario (nombre, email, vínculo con RRHH). El estado
 * no se toca aquí: es UC-06 (`UpdateUserStatusUseCase`).
 */
class UpdateUserUseCase
{
    private const ATTRIBUTES = ['name', 'email', 'employee_id', 'must_change_password'];

    public function execute(User $user, array $data): User
    {
        // El email del propio usuario no cuenta como duplicado.
        $data['user_id'] = $user->id;

        $data = RulesPipeline::run($data, [
            ValidateUniqueUserEmail::class,
        ]);

        $attributes = Arr::only($data, self::ATTRIBUTES);

        if (isset($attributes['email'])) {
            $attributes['email'] = strtolower((string) $attributes['email']);
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }

        return $user;
    }
}
