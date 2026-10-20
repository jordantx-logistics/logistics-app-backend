<?php

namespace Src\Organization\Adapters;

use Src\Organization\Models\Agency;
use Src\Shared\Contracts\AgencyDirectory;

/**
 * Adaptador Eloquent del puerto `AgencyDirectory`. Vive en el contexto dueño
 * del modelo (`Organization`).
 */
class EloquentAgencyDirectory implements AgencyDirectory
{
    public function agencyExists(int $id): bool
    {
        return Agency::query()->whereKey($id)->exists();
    }

    public function agencyIsActive(int $id): bool
    {
        return Agency::query()->whereKey($id)->where('is_active', true)->exists();
    }

    public function agencyUbigeo(int $id): ?string
    {
        $ubigeo = Agency::query()->whereKey($id)->value('ubigeo');

        return is_string($ubigeo) ? $ubigeo : null;
    }
}
