<?php

require_once __DIR__.'/BackofficeHelpers.php';

use Src\Fleet\Route\Services\ConsumptionCalculator;
use Src\Settings\Models\Setting;
use Src\Shared\Contracts\SettingsReader;
use Src\Shared\Models\AuditLog;

/*
|--------------------------------------------------------------------------
| Parámetros de negocio (Fase 7)
|--------------------------------------------------------------------------
*/

function patchSettings(array $settings)
{
    return test()->patchJson('/api/v1/settings', ['settings' => $settings]);
}

beforeEach(function () {
    $this->actingAs(adminUser());
});

it('lista el valor efectivo, que sin fila guardada es el de la configuración', function () {
    config(['fleet.per_diem_max' => '500.00']);

    $row = collect($this->getJson('/api/v1/settings')->assertOk()->json('data'))->firstWhere('key', 'fleet.per_diem_max');

    expect($row['value'])->toBe('500.00')
        ->and($row['is_overridden'])->toBeFalse();
});

it('guarda, audita y aplica el nuevo valor al instante', function () {
    expect(app(SettingsReader::class)->get('fleet.per_diem_max'))->toBe(config('fleet.per_diem_max'));

    patchSettings([['key' => 'fleet.per_diem_max', 'value' => '650']])->assertOk();

    expect(app(SettingsReader::class)->get('fleet.per_diem_max'))->toBe('650.00')
        ->and(AuditLog::query()->where('action', 'setting.updated')->count())->toBe(1);
});

it('rechaza claves fuera del catálogo y valores inválidos sin guardar nada', function (array $settings, string $code) {
    patchSettings($settings)
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', $code);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'clave desconocida' => [[['key' => 'app.key', 'value' => 'x']], 'SETTING_UNKNOWN'],
    'decimal fuera de rango' => [[['key' => 'fleet.per_diem_max', 'value' => '-1']], 'SETTING_INVALID'],
    'motor inexistente' => [[['key' => 'pricing.engine', 'value' => 'magic']], 'SETTING_INVALID'],
    'lote parcialmente inválido' => [[['key' => 'fleet.per_diem_max', 'value' => '10'], ['key' => 'pricing.engine', 'value' => 'magic']], 'SETTING_INVALID'],
]);

it('no deja un mínimo de consumo por encima del máximo, aunque llegue solo', function () {
    config(['fleet.consumption.truck.max' => 6]);

    patchSettings([['key' => 'fleet.consumption.truck.min', 'value' => '6']])
        ->assertUnprocessable()
        ->assertJsonPath('data.error_code', 'SETTING_RANGE_INVALID');
});

it('exige settings.manage', function () {
    $this->actingAs(fleetLessUser('analyst'));

    $this->getJson('/api/v1/settings')->assertForbidden();
});

it('el motor de precios obedece al parámetro', function () {
    patchSettings([['key' => 'pricing.engine', 'value' => 'tariff']])->assertOk();

    expect(app(SettingsReader::class)->get('pricing.engine'))->toBe('tariff');
});

it('el cálculo de consumo recibe el rango editado', function () {
    patchSettings([['key' => 'fleet.consumption.truck.max', 'value' => '20']])->assertOk();

    $reader = app(SettingsReader::class);

    expect((new ConsumptionCalculator)->isAnomalous(10.0, [
        'min' => (float) $reader->get('fleet.consumption.truck.min'),
        'max' => (float) $reader->get('fleet.consumption.truck.max'),
    ]))->toBeFalse();
});
