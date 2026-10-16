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
}
