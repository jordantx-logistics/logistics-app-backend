<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\Kpi;
use Src\Shared\Scope\AgencyScope;

/**
 * Indicadores del dashboard que aporta el contexto dueño de los datos. El
 * dashboard solo pide los de las fuentes cuyo permiso tiene el usuario.
 */
interface KpiSource
{
    /** Permiso que da derecho a ver estos indicadores. */
    public function permission(): string;

    /**
     * @return list<Kpi>
     */
    public function kpis(AgencyScope $scope): array;
}
