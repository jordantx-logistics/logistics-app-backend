<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Src\Customer\Adapters\EloquentCustomerDirectory;
use Src\Organization\Adapters\EloquentAgencyDirectory;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CustomerDirectory;

/**
 * Composition root de los puertos entre contextos.
 *
 * Aquí —y solo aquí— se juntan la interfaz (`Src\Shared\Contracts`) con el
 * adaptador del contexto dueño del modelo. Un contexto nunca importa a otro:
 * depende del puerto y este provider decide quién lo implementa (ADR-0006).
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerDirectory::class, EloquentCustomerDirectory::class);
        $this->app->singleton(AgencyDirectory::class, EloquentAgencyDirectory::class);
    }
}
