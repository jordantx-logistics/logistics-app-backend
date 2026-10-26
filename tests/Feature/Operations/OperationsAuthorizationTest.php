<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;

/*
|--------------------------------------------------------------------------
| Permisos de Operaciones (Fase 4)
|--------------------------------------------------------------------------
|
| Los roles operativos nuevos (`counter`, `courier`, `driver`,
| `warehouse-operator`, `analyst`) se siembran con el catǭlogo. Aqu�� se
| comprueba que cada uno ve lo suyo y recibe 403 en lo que no le toca: la
| autorizaci��n real es del backend, no del `meta.permission` del frontend.
|
*/

function operationsUserWithRole(string $role): User
{
    (new PermissionSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('deja al counter consultar env��os pero no recibirlos ni tocar inventario', function () {
    $counter = operationsUserWithRole('counter');

    $this->actingAs($counter)->getJson('/api/v1/operations/shipments')->assertOk();
    $this->actingAs($counter)->getJson('/api/v1/operations/tracking/ABCD1234')->assertNotFound();

    $this->actingAs($counter)
        ->postJson('/api/v1/operations/receptions', [])
        ->assertForbidden();

    $this->actingAs($counter)
        ->getJson('/api/v1/operations/inventory-counts')
        ->assertForbidden();
});

it('deja al courier ver y ejecutar entregas, pero no asignarlas', function () {
    $courier = operationsUserWithRole('courier');

    $this->actingAs($courier)->getJson('/api/v1/operations/deliveries')->assertOk();

    $this->actingAs($courier)
        ->postJson('/api/v1/operations/deliveries/assign', [])
        ->assertForbidden();

    $this->actingAs($courier)
        ->postJson('/api/v1/operations/inventory-counts', [])
        ->assertForbidden();
});

it('deja al warehouse-operator recibir, embarcar y contar inventario', function () {
    $operator = operationsUserWithRole('warehouse-operator');

    $this->actingAs($operator)->getJson('/api/v1/operations/shipments')->assertOk();
    $this->actingAs($operator)->getJson('/api/v1/operations/inventory-counts')->assertOk();
    $this->actingAs($operator)
        ->getJson('/api/v1/operations/inventory-counts?status=wat')
        ->assertStatus(422);

    $this->actingAs($operator)
        ->getJson('/api/v1/operations/deliveries')
        ->assertForbidden();
});

it('deja al analyst solo consultar y al driver solo tracking', function () {
    $analyst = operationsUserWithRole('analyst');

    $this->actingAs($analyst)->getJson('/api/v1/operations/deliveries')->assertOk();
    $this->actingAs($analyst)
        ->postJson('/api/v1/operations/embarks', [])
        ->assertForbidden();

    $driver = operationsUserWithRole('driver');

    $this->actingAs($driver)->getJson('/api/v1/operations/tracking/ABCD1234')->assertNotFound();
    $this->actingAs($driver)
        ->postJson('/api/v1/operations/scans/sync', [])
        ->assertForbidden();
});
