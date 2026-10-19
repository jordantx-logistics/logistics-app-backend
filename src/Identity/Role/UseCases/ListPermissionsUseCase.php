<?php

namespace Src\Identity\Role\UseCases;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Src\Identity\Role\Models\Permission;

/**
 * UseCase para listar permisos.
 */
class ListPermissionsUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * List all permissions with optional filtering
     *
     * @param  int|null  $id  Filter by permission ID
     * @return Collection<int, Permission>
     */
    public function execute(?int $id = null): Collection
    {
        $query = Permission::query();

        if ($id !== null) {
            $query->where('id', $id);
        }

        return $query->get();
    }

    /**
     * Find a permission by ID or throw exception
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Permission
    {
        $permission = $this->execute($id)->first();

        if (! $permission) {
            throw new ModelNotFoundException("Permission not found with ID {$id}");
        }

        return $permission;
    }
}
