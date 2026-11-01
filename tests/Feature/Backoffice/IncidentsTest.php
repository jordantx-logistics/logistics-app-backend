<?php

require_once __DIR__.'/BackofficeHelpers.php';

use Illuminate\Support\Facades\Log;
use Src\Incident\Models\Incident;
use Src\Shared\Models\AuditLog;

/*
|--------------------------------------------------------------------------
| Incidencias (Fase 7): ciclo, responsable, plazo y ámbito
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->origin = backofficeAgency('INC01');
    $this->destination = backofficeAgency('INC02');
    $this->outside = backofficeAgency('INC03');
    $this->order = backofficeOrder($this->origin, $this->destination);
    $this->agent = fleetLessUser('customer-service', $this->origin);
    $this->actingAs($this->agent);
});

function openIncident($order, array $overrides = [])
{
    return test()->postJson('/api/v1/incidents', array_merge([
        'order_id' => $order->id,
        'type' => 'damage',
        'description' => 'Box arrived crushed.',
    ], $overrides));
}

it('abre con el plazo del tipo y la foto de la orden', function () {
    config(['incidents.sla_hours.delay' => 24]);
    $this->freezeTime();

    $response = openIncident($this->order, ['type' => 'delay', 'priority' => 'high'])->assertCreated();

    expect($response->json('data.status'))->toBe('open')
        ->and($response->json('data.priority'))->toBe('high')
        ->and($response->json('data.ticket_code'))->toBe($this->order->ticket_code)
        ->and($response->json('data.due_at'))->toBe(now()->addHours(24)->toIso8601String())
        ->and(AuditLog::query()->where('action', 'incident.opened')->count())->toBe(1);
});

it('no abre sobre una orden inexistente ni fuera del ámbito', function () {
    $this->postJson('/api/v1/incidents', ['order_id' => 999999, 'type' => 'other', 'description' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('data.error_code', 'INCIDENT_ORDER_NOT_FOUND');

    openIncident(backofficeOrder($this->outside, $this->outside))
        ->assertForbidden()
        ->assertJsonPath('data.error_code', 'INCIDENT_AGENCY_SCOPE');
});

it('recorre el ciclo completo con sus reglas', function () {
    $id = openIncident($this->order)->json('data.id');

    $this->postJson("/api/v1/incidents/{$id}/start")
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'INCIDENT_NOT_ASSIGNED');

    $this->postJson("/api/v1/incidents/{$id}/assign", ['user_id' => $this->agent->id])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/close")
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'INCIDENT_INVALID_TRANSITION');

    $this->postJson("/api/v1/incidents/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

    $this->postJson("/api/v1/incidents/{$id}/resolve", ['note' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'INCIDENT_RESOLUTION_REQUIRED');

    $this->postJson("/api/v1/incidents/{$id}/resolve", ['note' => 'Replaced.'])->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson("/api/v1/incidents/{$id}/reopen")->assertOk()->assertJsonPath('data.resolution_note', null);
    $this->postJson("/api/v1/incidents/{$id}/resolve", ['note' => 'Replaced again.'])->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    $this->postJson("/api/v1/incidents/{$id}/assign", ['user_id' => $this->agent->id])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'INCIDENT_INVALID_TRANSITION');
});

it('solo asigna a quien atiende incidencias de esas agencias', function () {
    $id = openIncident($this->order)->json('data.id');

    $elsewhere = fleetLessUser('customer-service', $this->outside);
    $noPermission = fleetLessUser('counter', $this->origin);
    $national = adminUser();

    foreach ([$elsewhere, $noPermission] as $user) {
        $this->postJson("/api/v1/incidents/{$id}/assign", ['user_id' => $user->id])
            ->assertUnprocessable()
            ->assertJsonPath('data.error_code', 'INCIDENT_ASSIGNEE_INVALID');
    }

    $this->postJson("/api/v1/incidents/{$id}/assign", ['user_id' => $national->id])->assertOk();

    $candidates = collect($this->getJson("/api/v1/incidents/{$id}/assignees")->assertOk()->json('data'))->pluck('id');

    expect($candidates)->toContain($this->agent->id, $national->id)
        ->not->toContain($elsewhere->id, $noPermission->id);
});

it('filtra por ámbito y por vencidas', function () {
    $mine = openIncident($this->order)->json('data.id');
    Incident::query()->whereKey($mine)->update(['due_at' => now()->subHour()]);
    openIncident($this->order);

    $this->actingAs(adminUser());
    $foreign = openIncident(backofficeOrder($this->outside, $this->outside))->json('data.id');
    $this->actingAs($this->agent);

    $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/incidents?overdue=true')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine)
        ->assertJsonPath('data.0.is_overdue', true);
    $this->getJson("/api/v1/incidents/{$foreign}")->assertNotFound();
    $this->postJson("/api/v1/incidents/{$foreign}/assign", ['user_id' => $this->agent->id])
        ->assertForbidden();
});

it('permite consultar sin gestionar al counter y nada al cajero', function () {
    $id = openIncident($this->order)->json('data.id');

    $this->actingAs(fleetLessUser('counter', $this->origin));
    $this->getJson("/api/v1/incidents/{$id}")->assertOk();
    $this->postJson("/api/v1/incidents/{$id}/start")->assertForbidden();

    $this->actingAs(fleetLessUser('cashier', $this->origin));
    $this->getJson('/api/v1/incidents')->assertForbidden();
});

it('avisa una sola vez de cada incidencia vencida', function () {
    $id = openIncident($this->order)->json('data.id');
    Incident::query()->whereKey($id)->update(['due_at' => now()->subHour()]);

    Log::spy();

    $this->artisan('incidents:alert-overdue')->assertSuccessful();
    $this->artisan('incidents:alert-overdue')->assertSuccessful();

    Log::shouldHaveReceived('warning')->with('incident.overdue', Mockery::on(fn (array $context) => $context['incident_id'] === $id))->once();
});
