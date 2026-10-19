<?php

namespace Src\Identity\Attendance\Policies;

use App\Models\User;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Shared\Scope\AgencyScope;

/**
 * Reglas por registro sobre marcaciones.
 *
 * El `permission:` de la ruta decide *qué acción*; esta policy decide *sobre qué
 * marcación*: un usuario operativo solo alcanza las de sus agencias y un rol
 * nacional (`admin`, `analyst`) cualquiera. El listado ya lo acota el scope.
 */
class AttendancePolicy
{
    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /** El listado lo acota el scope: no hay regla por registro. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        return $this->inScope($record);
    }

    /** Anular (UC-18). */
    public function void(User $user, AttendanceRecord $record): bool
    {
        return $this->inScope($record);
    }

    private function inScope(AttendanceRecord $record): bool
    {
        return $this->scope->unrestricted
            || in_array((int) $record->agency_id, $this->scope->ids, true);
    }
}
