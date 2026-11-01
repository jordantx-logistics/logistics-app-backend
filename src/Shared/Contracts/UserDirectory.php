<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia `Identity/User`.
 *
 * Flota vincula la ficha del chofer a un usuario sin consultar la tabla `users`
 * (ADR-0006). El adaptador vive en `Src\Identity\User\Adapters` y se enlaza en
 * el composition root.
 */
interface UserDirectory
{
    public function userExists(int $id): bool;

    /** Nombre del usuario, para mostrar al chofer. */
    public function userName(int $id): ?string;

    /**
     * Usuarios con ese permiso que alcanzan alguna de las agencias, incluidos los
     * de alcance nacional.
     *
     * @param  list<int>  $agencyIds
     * @return list<array{id: int, name: string}>
     */
    public function usersWithPermission(string $permission, array $agencyIds): array;
}
