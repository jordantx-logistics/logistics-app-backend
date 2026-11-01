<?php

require_once __DIR__.'/BackofficeHelpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Src\Reporting\Jobs\GenerateReportExportJob;
use Src\Reporting\Models\ReportExport;

/*
|--------------------------------------------------------------------------
| Reportes y dashboard (Fase 7)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('local');

    $this->agency = backofficeAgency('REP01');
    $this->other = backofficeAgency('REP02');
    $this->order = backofficeOrder($this->agency, $this->agency, ['ticket_code' => 'MINE0001']);
    $this->foreign = backofficeOrder($this->other, $this->other, ['ticket_code' => 'OTHR0001']);
});

function exportPayload(array $overrides = []): array
{
    return array_merge([
        'report' => 'orders',
        'format' => 'csv',
        'from' => now()->subDay()->toDateString(),
        'to' => now()->toDateString(),
    ], $overrides);
}

/** Solicita la exportación y deja correr el outbox, como haría el scheduler. */
function requestExport(array $payload): int
{
    $id = test()->postJson('/api/v1/reports/exports', $payload)->assertStatus(202)->json('data.id');

    test()->artisan('outbox:consume')->assertSuccessful();

    return $id;
}

function exportedFile(ReportExport $export): string
{
    return Storage::disk('local')->get($export->path);
}

it('expone los seis reportes base', function () {
    $this->actingAs(adminUser());

    $keys = collect($this->getJson('/api/v1/reports/catalog')->assertOk()->json('data'))->pluck('key')->all();

    expect($keys)->toEqualCanonicalizing(['orders', 'revenue', 'cash-closures', 'tracking', 'fleet-consumption', 'incidents']);
});

it('encola en la cola reports y responde 202', function () {
    Bus::fake();
    $this->actingAs(adminUser());

    $this->postJson('/api/v1/reports/exports', exportPayload())
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending');

    $this->artisan('outbox:consume')->assertSuccessful();

    Bus::assertDispatched(GenerateReportExportJob::class, fn ($job) => $job->queue === 'reports');
});

it('genera el archivo en cada formato', function (string $format) {
    $this->actingAs(adminUser());

    $id = requestExport(exportPayload(['format' => $format]));
    $export = ReportExport::query()->findOrFail($id);

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(2)
        ->and($export->expires_at->isFuture())->toBeTrue()
        ->and(Storage::disk('local')->exists($export->path))->toBeTrue();

    $this->get("/api/v1/reports/exports/{$id}/download")->assertOk();
})->with(['csv', 'xlsx', 'pdf']);

it('el supervisor solo exporta su agencia, con o sin filtro', function () {
    $this->actingAs(fleetLessUser('agency-supervisor', $this->agency));

    $id = requestExport(exportPayload());
    $content = exportedFile(ReportExport::query()->findOrFail($id));

    expect($content)->toContain('MINE0001')->not->toContain('OTHR0001');

    $this->postJson('/api/v1/reports/exports', exportPayload(['agency_id' => $this->other->id]))
        ->assertForbidden()
        ->assertJsonPath('data.error_code', 'REPORT_AGENCY_SCOPE');
});

it('el analista exporta a nivel nacional y filtra por agencia y estado', function () {
    $this->actingAs(fleetLessUser('analyst'));

    $all = requestExport(exportPayload());
    expect(exportedFile(ReportExport::query()->findOrFail($all)))->toContain('MINE0001', 'OTHR0001');

    $one = requestExport(exportPayload(['agency_id' => $this->other->id]));
    expect(exportedFile(ReportExport::query()->findOrFail($one)))->toContain('OTHR0001')->not->toContain('MINE0001');

    $none = requestExport(exportPayload(['status' => 'cancelled']));
    expect(ReportExport::query()->findOrFail($none)->row_count)->toBe(0);
});

it('valida reporte, estado y rango', function () {
    $this->actingAs(adminUser());
    config(['reporting.max_range_days' => 10]);

    $this->postJson('/api/v1/reports/exports', exportPayload(['report' => 'nope']))->assertJsonPath('data.error_code', 'REPORT_UNKNOWN');
    $this->postJson('/api/v1/reports/exports', exportPayload(['status' => 'nope']))->assertJsonPath('data.error_code', 'REPORT_STATUS_INVALID');
    $this->postJson('/api/v1/reports/exports', exportPayload(['from' => now()->subDays(11)->toDateString()]))->assertJsonPath('data.error_code', 'REPORT_RANGE_TOO_LONG');
    $this->postJson('/api/v1/reports/exports', exportPayload(['from' => now()->toDateString(), 'to' => now()->subDay()->toDateString()]))->assertUnprocessable();
});

it('falla sin reintentar si el PDF supera el tope de filas', function () {
    $this->actingAs(adminUser());
    config(['reporting.pdf_max_rows' => 1]);

    $id = requestExport(exportPayload(['format' => 'pdf']));

    $this->getJson("/api/v1/reports/exports/{$id}")
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_code', 'REPORT_TOO_LARGE_FOR_PDF');

    $this->get("/api/v1/reports/exports/{$id}/download")->assertStatus(409);
});

it('neutraliza fórmulas en CSV sin tocar los números', function () {
    $this->actingAs(adminUser());
    $id = $this->postJson('/api/v1/incidents', ['order_id' => $this->order->id, 'type' => 'other', 'description' => '=HYPERLINK("http://x")'])->json('data.id');
    expect($id)->not->toBeNull();

    $export = requestExport(exportPayload(['report' => 'incidents']));

    expect(exportedFile(ReportExport::query()->findOrFail($export)))->toContain("'=HYPERLINK");
});

it('una exportación solo la ve quien la pidió', function () {
    $this->actingAs(adminUser());
    $id = requestExport(exportPayload());

    $this->actingAs(fleetLessUser('analyst'));
    $this->getJson("/api/v1/reports/exports/{$id}")->assertNotFound();
    $this->get("/api/v1/reports/exports/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson('/api/v1/reports/exports')->assertOk()->assertJsonCount(0, 'data');
});

it('reports.view lo tienen analista y supervisor, no el counter', function () {
    $this->actingAs(fleetLessUser('counter', $this->agency));

    $this->getJson('/api/v1/reports/catalog')->assertForbidden();
});

it('deja la exportación failed si el job agota sus intentos', function () {
    $this->actingAs(adminUser());
    Bus::fake();
    $id = requestExport(exportPayload());

    (new GenerateReportExportJob(1, ['export_id' => $id]))->failed(new RuntimeException('boom'));

    expect(ReportExport::query()->findOrFail($id)->error_code)->toBe('REPORT_GENERATION_FAILED');
});

it('purga las exportaciones vencidas con su archivo', function () {
    $this->actingAs(adminUser());
    $id = requestExport(exportPayload());
    $export = ReportExport::query()->findOrFail($id);
    $export->update(['expires_at' => now()->subMinute()]);

    $this->artisan('reports:purge-expired')->assertSuccessful();

    expect(ReportExport::query()->find($id))->toBeNull()
        ->and(Storage::disk('local')->exists($export->path))->toBeFalse();
});

it('el dashboard solo trae los indicadores de los permisos del usuario', function () {
    $this->actingAs(fleetLessUser('cashier', $this->agency));

    $keys = collect($this->getJson('/api/v1/dashboard/kpis')->assertOk()->json('data'))->pluck('key');

    expect($keys)->toContain('orders_today', 'revenue_today', 'cash_sessions_open')
        ->not->toContain('routes_in_progress', 'incidents_active');
});

it('el dashboard respeta el ámbito de agencia', function () {
    $this->actingAs(fleetLessUser('agency-supervisor', $this->agency));

    $today = collect($this->getJson('/api/v1/dashboard/kpis')->json('data'))->firstWhere('key', 'orders_today');

    expect($today['value'])->toBe('1');

    $this->actingAs(fleetLessUser('analyst'));

    $national = collect($this->getJson('/api/v1/dashboard/kpis')->json('data'))->firstWhere('key', 'orders_today');

    expect($national['value'])->toBe('2');
});

it('el selector de agencias no aplica ámbito ni expone inactivas', function () {
    backofficeAgency('REP03')->update(['is_active' => false]);
    $this->actingAs(fleetLessUser('counter', $this->agency));

    $codes = collect($this->getJson('/api/v1/organization/agencies/options')->assertOk()->json('data'))->pluck('code');

    expect($codes)->toContain('REP01', 'REP02')->not->toContain('REP03');
});
