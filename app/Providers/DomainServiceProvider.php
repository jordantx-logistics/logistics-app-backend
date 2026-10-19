<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Src\Customer\Adapters\EloquentCustomerDirectory;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Attendance\Policies\AttendancePolicy;
use Src\Identity\Device\Adapters\EloquentDeviceRegistrar;
use Src\Identity\Employee\Adapters\EloquentEmployeeDirectory;
use Src\Identity\Employee\Models\Employee;
use Src\Identity\Employee\Policies\EmployeePolicy;
use Src\Logistics\Order\Adapters\EloquentCustomerOrderQuery;
use Src\Logistics\Order\Jobs\GenerateOrderGuidePdfJob;
use Src\Logistics\Order\Jobs\ProjectOrderCreatedJob;
use Src\Logistics\Order\Jobs\SendOrderConfirmationMailJob;
use Src\Organization\Adapters\EloquentAgencyDirectory;
use Src\Organization\Models\Agency;
use Src\Organization\Policies\AgencyPolicy;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Contracts\CustomerOrderQuery;
use Src\Shared\Contracts\DeviceRegistrar;
use Src\Shared\Contracts\EmployeeDirectory;
use Src\Shared\Services\OutboxEventMap;

/**
 * Composition root de los puertos entre contextos.
 *
 * Aquí —y solo aquí— se juntan la interfaz (`Src\Shared\Contracts`) con el
 * adaptador del contexto dueño del modelo. Un contexto nunca importa a otro:
 * depende del puerto y este provider decide quién lo implementa (ADR-0006).
 *
 * Lo mismo vale para el outbox: quién consume cada evento se decide aquí, porque
 * `Shared` no puede conocer los jobs de un contexto.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerDirectory::class, EloquentCustomerDirectory::class);
        $this->app->singleton(CustomerOrderQuery::class, EloquentCustomerOrderQuery::class);
        $this->app->singleton(AgencyDirectory::class, EloquentAgencyDirectory::class);
        $this->app->singleton(EmployeeDirectory::class, EloquentEmployeeDirectory::class);
        $this->app->singleton(DeviceRegistrar::class, EloquentDeviceRegistrar::class);

        // Policies: los módulos no tienen provider propio (sus rutas y comandos
        // se descubren solos), así que el registro de quién protege qué modelo
        // vive aquí, junto al resto del cableado.
        Gate::policy(Agency::class, AgencyPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(AttendanceRecord::class, AttendancePolicy::class);

        $this->app->singleton(OutboxEventMap::class, fn (): OutboxEventMap => new OutboxEventMap([
            // `order.created` → Core (`spec/02-objetivo/05-asincronia-y-eventos.md`).
            // Cada job decide su cola: PDF y correo en `default`, proyección en `sync`.
            'order.created' => [
                GenerateOrderGuidePdfJob::class,
                SendOrderConfirmationMailJob::class,
                ProjectOrderCreatedJob::class,
            ],
        ]));
    }
}
