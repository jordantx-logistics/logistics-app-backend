<?php

namespace Src\Shared\Traits;

use Illuminate\Database\Eloquent\Builder;
use Src\Shared\Scope\AgencyScope;

/**
 * Ámbito de agencia reutilizable (paso 2.3 de la Fase 2).
 *
 * El filtro por agencia estaba duplicado a mano en cada consulta; ahora vive
 * aquí y los modelos que se acotan por agencia solo declaran la columna. Un
 * array vacío no ve nada: un usuario operativo sin ámbito es un error de
 * configuración, no un nacional; el bypass de los roles nacionales llega ya
 * resuelto en el `AgencyScope` (`unrestricted`).
 *
 * Los modelos cuya consulta no casa con una sola columna redefinen
 * `scopeForAgencies` (así lo hace `Logistics\Order\Models\Order`, que filtra por
 * origen **o** destino); el resto solo hereda.
 */
trait CanBeScopedByAgency
{
    /**
     * Acota la consulta a las agencias de la lista.
     *
     * @param  Builder<static>  $query
     * @param  list<int>  $ids
     * @return Builder<static>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->whereIn($this->agencyScopeColumn(), $ids);
    }

    /**
     * Acota la consulta al ámbito de la petición: sin filtro si es nacional,
     * por sus agencias si no.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAgencyScope(Builder $query, AgencyScope $scope): Builder
    {
        return $scope->unrestricted ? $query : $query->forAgencies($scope->ids);
    }

    /**
     * Columna que guarda el id de agencia. `agencies` guarda su propio id, así
     * que lo redefine.
     */
    public function agencyScopeColumn(): string
    {
        return 'agency_id';
    }
}
