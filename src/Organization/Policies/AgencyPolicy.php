<?php

namespace Src\Organization\Policies;

use App\Models\User;
use Src\Organization\Models\Agency;
use Src\Shared\Scope\AgencyScope;

/**
 * Reglas por registro sobre agencias (paso 2.4 de la Fase 2).
 *
 * El `permission:agencies.view` / `agencies.manage` de la ruta decide *qué
 * acción* puede hacer el usuario; esta policy decide *sobre qué agencia*. El
 * listado ya lo acota el scope; aquí se cierra el hueco de pedir por id la
 * agencia de otro (una petición con solo el permiso llegaba al modelo).
 *
 * El ámbito llega por constructor: es el de la petición
 * (`Src\System\Middleware\ResolveAgencyScope`). Fuera de una petición autenticada
 * el ámbito es vacío, así que la respuesta por defecto es denegar.
 */
class AgencyPolicy
{
    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /** El listado lo acota el scope: no hay regla por registro. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Agency $agency): bool
    {
        return $this->scope->covers($agency->id);
    }

    /**
     * Crear agencias es una operación nacional (UC-14, actor `admin`): un
     * supervisor administra la suya, no da de alta agencias nuevas.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Agency $agency): bool
    {
        return $this->scope->covers($agency->id);
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $this->scope->covers($agency->id);
    }
}
