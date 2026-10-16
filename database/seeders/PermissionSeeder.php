<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeder para crear roles y permisos por defecto.
 *
 * Este seeder crea:
 * - Todos los permisos del sistema por módulo
 * - Los roles definidos: SuperAdmin, Admin, Manager, Operator, Viewer
 * - Asigna los permisos correspondientes a cada rol
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->createPermissions();
        $this->createRoles();
    }

    /**
     * Create all permissions for the system modules.
     */
    private function createPermissions(): void
    {
        $permissions = [
            // Roles
            ['name' => 'role.index', 'guard_name' => 'api'],
            ['name' => 'role.store', 'guard_name' => 'api'],
            ['name' => 'role.update', 'guard_name' => 'api'],
            ['name' => 'role.destroy', 'guard_name' => 'api'],
            ['name' => 'role.show', 'guard_name' => 'api'],

            // Permissions
            ['name' => 'permission.index', 'guard_name' => 'api'],
            ['name' => 'permission.store', 'guard_name' => 'api'],
            ['name' => 'permission.update', 'guard_name' => 'api'],
            ['name' => 'permission.destroy', 'guard_name' => 'api'],
            ['name' => 'permission.show', 'guard_name' => 'api'],

            // Users
            ['name' => 'user.index', 'guard_name' => 'api'],
            ['name' => 'user.store', 'guard_name' => 'api'],
            ['name' => 'user.update', 'guard_name' => 'api'],
            ['name' => 'user.destroy', 'guard_name' => 'api'],
            ['name' => 'user.show', 'guard_name' => 'api'],
            ['name' => 'user.assign_role', 'guard_name' => 'api'],
            ['name' => 'user.assign_permission', 'guard_name' => 'api'],

            // Orders
            ['name' => 'order.index', 'guard_name' => 'api'],
            ['name' => 'order.store', 'guard_name' => 'api'],
            ['name' => 'order.update', 'guard_name' => 'api'],
            ['name' => 'order.destroy', 'guard_name' => 'api'],
            ['name' => 'order.show', 'guard_name' => 'api'],

            // Payments
            ['name' => 'payment.index', 'guard_name' => 'api'],
            ['name' => 'payment.store', 'guard_name' => 'api'],
            ['name' => 'payment.update', 'guard_name' => 'api'],
            ['name' => 'payment.destroy', 'guard_name' => 'api'],
            ['name' => 'payment.show', 'guard_name' => 'api'],

            // Agencies
            ['name' => 'agency.index', 'guard_name' => 'api'],
            ['name' => 'agency.store', 'guard_name' => 'api'],
            ['name' => 'agency.update', 'guard_name' => 'api'],
            ['name' => 'agency.destroy', 'guard_name' => 'api'],
            ['name' => 'agency.show', 'guard_name' => 'api'],

            // Persons
            ['name' => 'person.index', 'guard_name' => 'api'],
            ['name' => 'person.store', 'guard_name' => 'api'],
            ['name' => 'person.update', 'guard_name' => 'api'],
            ['name' => 'person.destroy', 'guard_name' => 'api'],
            ['name' => 'person.show', 'guard_name' => 'api'],

            // Companies
            ['name' => 'company.index', 'guard_name' => 'api'],
            ['name' => 'company.store', 'guard_name' => 'api'],
            ['name' => 'company.update', 'guard_name' => 'api'],
            ['name' => 'company.destroy', 'guard_name' => 'api'],
            ['name' => 'company.show', 'guard_name' => 'api'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate($permission);
        }
    }

    /**
     * Create all roles with their assigned permissions.
     */
    private function createRoles(): void
    {
        // SuperAdmin - todos los permisos
        $superAdmin = Role::firstOrCreate(
            ['name' => 'SuperAdmin'],
            ['guard_name' => 'api']
        );
        $superAdmin->givePermissionTo(Permission::all());

        // Admin - roles, permissions, users
        $admin = Role::firstOrCreate(
            ['name' => 'Admin'],
            ['guard_name' => 'api']
        );
        $admin->givePermissionTo([
            'role.index', 'role.store', 'role.update', 'role.destroy', 'role.show',
            'permission.index', 'permission.store', 'permission.update', 'permission.destroy', 'permission.show',
            'user.index', 'user.store', 'user.update', 'user.destroy', 'user.show',
            'user.assign_role', 'user.assign_permission',
        ]);

        // Manager - orders, payments, agencies, persons, companies
        $manager = Role::firstOrCreate(
            ['name' => 'Manager'],
            ['guard_name' => 'api']
        );
        $manager->givePermissionTo([
            'order.index', 'order.store', 'order.update', 'order.destroy', 'order.show',
            'payment.index', 'payment.store', 'payment.update', 'payment.destroy', 'payment.show',
            'agency.index', 'agency.store', 'agency.update', 'agency.destroy', 'agency.show',
            'person.index', 'person.store', 'person.update', 'person.destroy', 'person.show',
            'company.index', 'company.store', 'company.update', 'company.destroy', 'company.show',
        ]);

        // Operator - solo orders (index, store, show)
        $operator = Role::firstOrCreate(
            ['name' => 'Operator'],
            ['guard_name' => 'api']
        );
        $operator->givePermissionTo([
            'order.index', 'order.store', 'order.show',
        ]);

        // Viewer - todos los módulos (solo lectura)
        $viewer = Role::firstOrCreate(
            ['name' => 'Viewer'],
            ['guard_name' => 'api']
        );
        $viewer->givePermissionTo(Permission::all()->filter(function ($p) {
            return in_array($p->name, [
                'role.index', 'permission.index', 'user.index', 'order.index',
                'payment.index', 'agency.index', 'person.index', 'company.index',
            ]);
        }));
    }
}
