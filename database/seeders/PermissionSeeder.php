<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use Src\Identity\Role\Models\Permission;
use Src\Identity\Role\Models\Role;

/**
 * Catálogo de permisos y roles (idempotente).
 *
 * Los permisos son `recurso.accion` en inglés
 * (`spec/02-objetivo/04-autorizacion.md`) y cubren las rutas de negocio vivas:
 * cada `permission:` de los `routes.php` de `src/` apunta a uno de esta lista
 * (`tests/Feature/Architecture/AuthorizationRoutesTest.php` lo comprueba).
 *
 * Los roles son los del spec. Los que todavía no tienen permisos (sus módulos
 * llegan en fases posteriores: caja, almacén, flota, reparto, reportes) quedan
 * creados y vacíos, para poder asignarlos desde ya.
 *
 * El guard es el que Spatie usa en tiempo de ejecución
 * (`Guard::getDefaultName()` → `config('auth.defaults.guard')`, hoy `web`), así
 * que seeder y comprobación nunca se separan.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Permisos de las rutas de negocio actuales.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        // Logística (Fase 3).
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.confirm',
        'orders.cancel',
        'customers.view',
        'customers.manage',
        'tariffs.manage',
        'locations.manage',

        'agencies.view',
        'agencies.manage',
        'roles.view',
        'roles.manage',
        'permissions.view',
        'permissions.manage',
        'users.manage',

        // Identity (Fase 2): RRHH, marcaciones y dispositivos.
        'employees.manage',
        'attendance.view',
        'attendance.register',
        'attendance.manage',
        'devices.manage',

        // Operaciones / Tracking (Fase 4).
        'shipments.view',
        'shipments.receive',
        'shipments.embark',
        'shipments.disembark',
        'tracking.scan',
        'tracking.view',
        'inventory.manage',
        'delivery.view',
        'delivery.assign',
        'delivery.execute',
    ];

    /**
     * Rol → permisos. `syncPermissions` los deja exactamente así, sin duplicar.
     *
     * @var array<string, list<string>>
     */
    public const ROLES = [
        'admin' => self::PERMISSIONS,

        // Operación de su agencia (el filtro por agencia es el scope `forAgencies`).
        'agency-supervisor' => [
            'orders.view', 'orders.create', 'orders.update', 'orders.confirm', 'orders.cancel',
            'customers.view', 'customers.manage',
            'agencies.view', 'agencies.manage',
            'shipments.view', 'shipments.receive', 'shipments.embark', 'shipments.disembark',
            'tracking.scan', 'tracking.view', 'inventory.manage',
            'delivery.view', 'delivery.assign',
        ],

        'counter' => [
            'orders.view', 'orders.create', 'orders.update',
            'customers.view', 'customers.manage',
            'shipments.view', 'tracking.view',
        ],

        'customer-service' => [
            'orders.view',
            'customers.view',
            'shipments.view', 'tracking.view',
        ],

        // Nacional de lectura: ve todas las órdenes (`hasNationalScope`) pero no
        // las toca.
        'analyst' => [
            'orders.view',
            'customers.view',
            'shipments.view', 'tracking.view', 'delivery.view',
        ],

        'cashier' => [],

        'warehouse-operator' => [
            'shipments.view', 'shipments.receive', 'shipments.embark', 'shipments.disembark',
            'tracking.scan', 'tracking.view', 'inventory.manage',
        ],

        'driver' => ['tracking.view'],

        'courier' => ['delivery.view', 'delivery.execute', 'tracking.view'],

        // Marcaciones propias (Fase 2): el único rol operativo que ya tiene
        // permiso; los de RRHH llegan con los endpoints de Identity.
        'employee' => ['attendance.register'],
    ];

    public function run(): void
    {
        // Los permisos viven cacheados (Redis en local/producción): hay que
        // invalidar antes de tocarlos o las rutas seguirían viendo el catálogo viejo.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = Guard::getDefaultName(Permission::class);

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        // El catálogo es la fuente de verdad: un permiso retirado (p. ej.
        // `persons.*`/`companies.*`, que la Fase 3 sustituye por `customers.*`)
        // se borra para que ningún `routes.php` pueda seguir declarándolo.
        Permission::query()->whereNotIn('name', self::PERMISSIONS)->delete();

        // `findOrCreate` deja la colección de permisos *cargada y vacía* en el
        // registrador de Spatie (la leyó antes de crearlos). Sin invalidarla
        // aquí, `syncPermissions` no los encontraría: `findByName` mira la caché.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $name => $permissions) {
            Role::findOrCreate($name, $guard)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
