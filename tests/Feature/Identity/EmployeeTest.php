<?php

use App\Models\User;
use Src\Identity\Employee\Models\Employee;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
    $this->agency = Agency::factory()->create();
});

/** Payload válido de alta de empleado (una sola definición, tests legibles). */
function makeEmployeePayload(int $agencyId, array $overrides = []): array
{
    return array_merge([
        'document_type' => 'DNI',
        'document_number' => '12345678',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
        'email' => 'ana@empresa.com',
        'phone' => '999888777',
        'position' => 'counter',
        'agency_id' => $agencyId,
        'hired_at' => '2026-01-05',
        'status' => 'active',
    ], $overrides);
}

it('da de alta un empleado', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/employees', makeEmployeePayload($this->agency->id));

    $response->assertStatus(201)
        ->assertJsonPath('data.document_type', 'DNI')
        ->assertJsonPath('data.document_number', '12345678')
        ->assertJsonPath('data.full_name', 'Ana Quispe')
        ->assertJsonPath('data.agency_id', $this->agency->id)
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('employees', [
        'document_number' => '12345678',
        'agency_id' => $this->agency->id,
        'status' => 'active',
    ]);
});

it('rechaza un documento duplicado', function () {
    Employee::factory()->create([
        'document_type' => 'DNI',
        'document_number' => '12345678',
    ]);

    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/employees', makeEmployeePayload($this->agency->id));

    $response->assertStatus(409)
        ->assertJsonPath('data.error_code', 'EMPLOYEE_DOCUMENT_TAKEN');
});

it('rechaza una agencia inexistente o inactiva', function () {
    $inactiva = Agency::factory()->create(['is_active' => false]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/employees', makeEmployeePayload($inactiva->id))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'EMPLOYEE_AGENCY_INVALID');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/employees', makeEmployeePayload(999999))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'EMPLOYEE_AGENCY_INVALID');
});

it('actualiza un empleado sin chocar con su propio documento', function () {
    $employee = Employee::factory()->create([
        'agency_id' => $this->agency->id,
        'document_number' => '12345678',
        'position' => 'counter',
    ]);

    $response = $this->withHeaders($this->headers)
        ->putJson("/api/v1/identity/employees/{$employee->id}", makeEmployeePayload($this->agency->id, [
            'position' => 'cashier',
            'last_name' => 'Quispe Rojas',
        ]));

    $response->assertOk()
        ->assertJsonPath('data.id', $employee->id)
        ->assertJsonPath('data.position', 'cashier')
        ->assertJsonPath('data.full_name', 'Ana Quispe Rojas');

    expect($employee->fresh()->position)->toBe('cashier');
});

it('da de baja al empleado y desactiva su usuario', function () {
    $employee = Employee::factory()->create(['agency_id' => $this->agency->id]);

    // `employee_id` no es fillable en `App\Models\User` (solo `name/email/password`),
    // así que el vínculo se fuerza a mano.
    $linkedUser = User::factory()->create();
    $linkedUser->forceFill(['employee_id' => $employee->id, 'status' => 'active'])->save();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/identity/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($employee->fresh()->status)->toBe('terminated')
        ->and($employee->fresh()->terminated_at)->not->toBeNull()
        ->and($linkedUser->fresh()->status)->toBe('inactive');
});

it('lista los empleados acotados por agencia nacional', function () {
    Employee::factory()->count(3)->create(['agency_id' => $this->agency->id]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/employees?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);
});

it('filtra el listado por estado y texto', function () {
    Employee::factory()->create(['agency_id' => $this->agency->id, 'status' => 'active', 'last_name' => 'Quispe']);
    Employee::factory()->create(['agency_id' => $this->agency->id, 'status' => 'terminated', 'last_name' => 'Rojas']);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/employees?status=terminated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'terminated');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/employees?q=Quispe')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/*
|--------------------------------------------------------------------------
| Aislamiento por agencia (criterio 1) y autorización
|--------------------------------------------------------------------------
*/

it('acota el listado y el detalle a las agencias del usuario', function () {
    $agenciaA = Agency::factory()->create();
    $agenciaB = Agency::factory()->create();

    $propio = Employee::factory()->create(['agency_id' => $agenciaA->id]);
    $ajeno = Employee::factory()->create(['agency_id' => $agenciaB->id]);

    $supervisor = adminUser();
    $supervisor->removeRole('admin');
    $supervisor->givePermissionTo('employees.manage');
    $supervisor->agencies()->attach($agenciaA);

    $this->actingAs($supervisor)
        ->getJson('/api/v1/identity/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $propio->id);

    $this->actingAs($supervisor)
        ->getJson("/api/v1/identity/employees/{$propio->id}")
        ->assertOk();

    $this->actingAs($supervisor)
        ->getJson("/api/v1/identity/employees/{$ajeno->id}")
        ->assertForbidden();
});

it('responde 403 sin el permiso employees.manage', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('cashier');

    $this->actingAs($user)
        ->getJson('/api/v1/identity/employees')
        ->assertForbidden();
});

it('exige sesión para listar empleados', function () {
    $this->getJson('/api/v1/identity/employees')->assertUnauthorized();
});
