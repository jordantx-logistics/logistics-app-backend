<?php

namespace Src\Identity\Role\UseCases;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Src\Identity\Role\Models\Role;

/**
 * UseCase para listar roles.
 */
class ListRolesUseCase
{
    /**
     * Todos los roles con sus permisos ya cargados: `RoleResource` los pinta, y
     * sin el `with` el listado haría una consulta por rol.
     *
     * @param  int|null  $id  Filtra por id de rol
     * @return Collection<int, Role>
     */
    public function execute(?int $id = null): Collection
    {
        $query = Role::query()->with('permissions');

        if ($id !== null) {
            $query->where('id', $id);
        }

        return $query->get();
    }

    /**
     * Find a role by ID or throw exception
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Role
    {
        $role = $this->execute($id)->first();

        if (! $role) {
            throw new ModelNotFoundException("Role not found with ID {$id}");
        }

        return $role;
    }
}
