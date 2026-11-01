<?php

namespace Src\Settings\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Settings\Models\Setting;
use Src\Settings\Requests\UpdateSettingsRequest;
use Src\Settings\UseCases\UpdateSettingsUseCase;

/**
 * @group Settings
 *
 * Parámetros de negocio editables por el administrador.
 */
class SettingsController extends Controller
{
    /**
     * List business settings
     *
     * Returns the effective value of each editable parameter and the value that
     * applies when nothing is stored.
     *
     * @response 200 scenario="Settings" {"success": true, "message": "Settings retrieved successfully.", "data": [{"key": "fleet.per_diem_max", "group": "fleet", "label": "Per diem limit per route (PEN)", "type": "decimal", "options": null, "min": 0, "max": 100000, "value": "500.00", "default": "500.00", "is_overridden": false, "updated_by": null, "updated_at": null}]}
     */
    public function index(): JsonResponse
    {
        return $this->success($this->listing(), 'Settings retrieved successfully.');
    }

    /**
     * Update business settings
     *
     * The batch is atomic and audited: an invalid value leaves every parameter as
     * it was.
     *
     * @response 200 scenario="Updated" {"success": true, "message": "Settings updated successfully.", "data": [{"key": "fleet.per_diem_max", "group": "fleet", "label": "Per diem limit per route (PEN)", "type": "decimal", "options": null, "min": 0, "max": 100000, "value": "600.00", "default": "500.00", "is_overridden": true, "updated_by": 1, "updated_at": "2026-10-31T12:00:00+00:00"}]}
     */
    public function update(UpdateSettingsRequest $request, UpdateSettingsUseCase $useCase): JsonResponse
    {
        $useCase->execute($request->validated('settings'));

        return $this->success($this->listing(), 'Settings updated successfully.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(): array
    {
        $stored = Setting::query()->get()->keyBy('key');
        $rows = [];

        foreach (config('settings.catalog') as $key => $definition) {
            $setting = $stored->get($key);

            $rows[] = [
                'key' => $key,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'options' => $definition['options'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'value' => (string) ($setting->value ?? config($key)),
                'default' => (string) config($key),
                'is_overridden' => $setting !== null,
                'updated_by' => $setting?->updated_by,
                'updated_at' => $setting?->updated_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
