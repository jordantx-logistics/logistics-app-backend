<?php

namespace Src\Identity\Employee\Adapters;

use Src\Identity\Employee\Models\Employee;
use Src\Shared\Contracts\EmployeeDirectory;

/**
 * Adaptador Eloquent del puerto `EmployeeDirectory`. Vive en el contexto dueño
 * del modelo (`Identity/Employee`).
 */
class EloquentEmployeeDirectory implements EmployeeDirectory
{
    public function employeeExists(int $id): bool
    {
        return Employee::query()->whereKey($id)->exists();
    }

    public function employeeIsActive(int $id): bool
    {
        return Employee::query()
            ->whereKey($id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->exists();
    }

    public function employeeAgencyId(int $id): ?int
    {
        $agencyId = Employee::query()->whereKey($id)->value('agency_id');

        return $agencyId === null ? null : (int) $agencyId;
    }
}
