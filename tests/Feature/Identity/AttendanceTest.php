<?php

use Illuminate\Support\Facades\DB;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Employee\Models\Employee;
use Src\Organization\Models\Agency;

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
    $this->agency = Agency::factory()->create();
    $this->employee = Employee::factory()->create([
        'agency_id' => $this->agency->id,
        'status' => Employee::STATUS_ACTIVE,
    ]);
});

function attendancePayload(int $employeeId, int $agencyId, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employeeId,
        'agency_id' => $agencyId,
        'type' => AttendanceRecord::TYPE_IN,
        'marked_at' => '2026-10-15T08:00:00-05:00',
        'source' => 'pda',
        'idempotency_key' => 'att-'.uniqid(),
    ], $overrides);
}

it('registra una marcación y crea el consolidado del día', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id));

    $response->assertStatus(201)
        ->assertJsonPath('data.employee_id', $this->employee->id)
        ->assertJsonPath('data.type', AttendanceRecord::TYPE_IN)
        ->assertJsonPath('data.status', AttendanceRecord::STATUS_VALID);

    $this->assertDatabaseHas('attendance_records', [
        'employee_id' => $this->employee->id,
        'type' => AttendanceRecord::TYPE_IN,
    ]);

    $day = DB::table('attendance_days')->where('employee_id', $this->employee->id)->first();

    expect($day)->not->toBeNull()
        ->and($day->status)->toBe('incomplete')
        ->and((int) $day->worked_minutes)->toBe(0);
});

it('es idempotente por idempotency_key', function () {
    $payload = attendancePayload($this->employee->id, $this->agency->id);

    $this->withHeaders($this->headers)->postJson('/api/v1/identity/attendance', $payload)->assertStatus(201);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', $payload)
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'ATTENDANCE_DUPLICATE');

    expect(AttendanceRecord::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('no permite una salida sin entrada previa', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id, [
            'type' => AttendanceRecord::TYPE_OUT,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'ATTENDANCE_SEQUENCE_INVALID');
});

it('calcula los minutos trabajados con la entrada y la salida', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id))
        ->assertStatus(201);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id, [
            'type' => AttendanceRecord::TYPE_OUT,
            'marked_at' => '2026-10-15T17:00:00-05:00',
        ]))
        ->assertStatus(201);

    $day = DB::table('attendance_days')->where('employee_id', $this->employee->id)->first();

    expect($day->status)->toBe('complete')
        ->and((int) $day->worked_minutes)->toBe(540);
});

it('rechaza marcar con un empleado dado de baja', function () {
    $terminado = Employee::factory()->create([
        'agency_id' => $this->agency->id,
        'status' => Employee::STATUS_TERMINATED,
    ]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($terminado->id, $this->agency->id))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'EMPLOYEE_INACTIVE');
});

it('rechaza marcar con una agencia inexistente o inactiva', function () {
    $inactiva = Agency::factory()->create(['is_active' => false]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $inactiva->id))
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'ATTENDANCE_AGENCY_INVALID');
});

/*
|--------------------------------------------------------------------------
| Self-service acotado y anulación
|--------------------------------------------------------------------------
*/

it('deja que un empleado marque su propia ficha, pero no la de otro', function () {
    $otro = Employee::factory()->create(['agency_id' => $this->agency->id]);

    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('employee');
    $user->forceFill(['employee_id' => $this->employee->id])->save();

    $this->actingAs($user)
        ->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id))
        ->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $this->actingAs($user)
        ->postJson('/api/v1/identity/attendance', attendancePayload($otro->id, $this->agency->id))
        ->assertStatus(403)
        ->assertJsonPath('data.error_code', 'ATTENDANCE_FOR_ANOTHER_EMPLOYEE');
});

it('anula una marcación y recalcula el consolidado', function () {
    $record = AttendanceRecord::factory()->create([
        'employee_id' => $this->employee->id,
        'agency_id' => $this->agency->id,
        'type' => AttendanceRecord::TYPE_IN,
        'marked_at' => '2026-10-15 08:00:00',
    ]);

    DB::table('attendance_days')->insert([
        'employee_id' => $this->employee->id,
        'work_date' => '2026-10-15',
        'worked_minutes' => 0,
        'late_minutes' => 0,
        'status' => 'incomplete',
    ]);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/identity/attendance/{$record->id}/void")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($record->fresh()->status)->toBe(AttendanceRecord::STATUS_VOIDED)
        ->and(DB::table('attendance_days')->where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Listado, consolidado y autorización
|--------------------------------------------------------------------------
*/

it('lista las marcaciones acotadas a las agencias del usuario', function () {
    $agenciaB = Agency::factory()->create();
    $empleadoB = Employee::factory()->create(['agency_id' => $agenciaB->id]);

    AttendanceRecord::factory()->create(['employee_id' => $this->employee->id, 'agency_id' => $this->agency->id]);
    AttendanceRecord::factory()->create(['employee_id' => $empleadoB->id, 'agency_id' => $agenciaB->id]);

    $supervisor = adminUser();
    $supervisor->removeRole('admin');
    $supervisor->givePermissionTo('attendance.view');
    $supervisor->agencies()->attach($this->agency);

    $this->actingAs($supervisor)
        ->getJson('/api/v1/identity/attendance')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.agency_id', $this->agency->id);
});

it('devuelve el consolidado por empleado', function () {
    DB::table('attendance_days')->insert([
        'employee_id' => $this->employee->id,
        'work_date' => '2026-10-15',
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'status' => 'complete',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/identity/attendance/summary?employee_id='.$this->employee->id)
        ->assertOk()
        ->assertJsonPath('data.0.employee_id', $this->employee->id)
        ->assertJsonPath('data.0.worked_minutes', 540)
        ->assertJsonPath('data.0.status', 'complete');
});

it('responde 403 al listar sin attendance.view', function () {
    $user = adminUser();
    $user->removeRole('admin');
    $user->assignRole('cashier');

    $this->actingAs($user)
        ->getJson('/api/v1/identity/attendance')
        ->assertForbidden();
});

it('exige sesión para registrar marcaciones', function () {
    $this->postJson('/api/v1/identity/attendance', attendancePayload($this->employee->id, $this->agency->id))
        ->assertUnauthorized();
});
