<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Src\Identity\User\Rules\ValidateStrongPassword;
use Src\Identity\User\Rules\ValidateUniqueUserEmail;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Alta de usuario por un administrador (UC-01). No hay registro público.
 *
 * Única puerta de entrada: valida email único y política de contraseña, crea el
 * usuario con el rol por defecto (`employee`, salvo roles explícitos) y fija su
 * ámbito de agencia —lo que además exige la invariante «un rol operativo necesita
 * agencia»—.
 *
 * No emite `user.created`: el outbox manda a `failed` un `event_type` sin
 * consumidor (deuda anotada en el cierre de la Fase 2).
 */
class RegisterUserUseCase
{
    /** Rol por defecto cuando el alta no indica roles (UC-01). */
    private const DEFAULT_ROLE = 'employee';

    public function __construct(
        private readonly AssignAgencyScopeUseCase $assignAgencyScope,
    ) {}

    public function execute(array $data): User
    {
        $data = RulesPipeline::run($data, [
            ValidateUniqueUserEmail::class,
            ValidateStrongPassword::class,
        ]);

        return DB::transaction(function () use ($data): User {
            $user = new User;

            // `forceFill`: `status`, `employee_id`... no son fillable en
            // `App\Models\User` (solo `name`, `email`, `password`), y el cast
            // `hashed` del modelo se encarga de la contraseña.
            $user->forceFill([
                'name' => $data['name'],
                'email' => strtolower((string) $data['email']),
                'password' => $data['password'],
                'status' => User::STATUS_ACTIVE,
                'employee_id' => $data['employee_id'] ?? null,
                'must_change_password' => $data['must_change_password'] ?? false,
                'created_by' => auth()->id(),
            ])->save();

            $roles = $data['roles'] ?? [];

            $user->syncRoles($roles === [] ? [self::DEFAULT_ROLE] : $roles);

            $this->assignAgencyScope->execute(
                $user,
                array_map('intval', $data['agency_ids'] ?? []),
                isset($data['primary_agency_id']) ? (int) $data['primary_agency_id'] : null,
            );

            return $user->fresh(['agencies']);
        });
    }
}
