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
}
