<?php

use Database\Seeders\PermissionSeeder;
use Src\Identity\Role\Models\Permission;
use Src\Identity\Role\Models\Role;

/*
|--------------------------------------------------------------------------
| Catálogo de permisos y roles (Fase 2, paso 2)
|--------------------------------------------------------------------------
|
| El catálogo crece con los permisos de Identity y los roles se siembran con su
| mapa del spec: `employee` solo marca su asistencia y el resto de permisos de
| RRHH quedan en `admin` hasta que existan sus endpoints.
|
*/

it('siembra los permisos de Identity de la Fase 2', function () {
    (new PermissionSeeder)->run();

    foreach (['employees.manage', 'attendance.register', 'attendance.manage', 'devices.manage'] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('da todos los permisos al admin y solo las marcaciones al employee', function () {
    (new PermissionSeeder)->run();

    expect(Role::findByName('admin')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(PermissionSeeder::PERMISSIONS)
        ->and(Role::findByName('employee')->permissions->pluck('name')->all())
        ->toBe(['attendance.register']);
});
