<?php

namespace Src\Settings\UseCases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Src\Settings\Adapters\DatabaseSettingsReader;
use Src\Settings\Models\Setting;
use Src\Shared\Contracts\SettingsReader;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Services\AuditLogger;

/**
 * Guarda un lote de parámetros, atómico y auditado: un valor inválido deja todos
 * como estaban.
 */
class UpdateSettingsUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsReader $settings,
    ) {}

    /**
     * @param  list<array{key: string, value: mixed}>  $changes
     */
    public function execute(array $changes): void
    {
        $values = [];

        foreach ($changes as $change) {
            $values[$change['key']] = $this->normalize($change['key'], $change['value']);
        }

        DB::transaction(function () use ($values): void {
            // Por clave ascendente: dos lotes solapados bloquean en el mismo orden.
            ksort($values);

            Setting::query()->whereIn('key', array_keys($values))->orderBy('key')->lockForUpdate()->get();

            $this->assertConsumptionRanges($values);

            foreach ($values as $key => $value) {
                $before = $this->settings->get($key);

                $setting = Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by' => auth()->id()],
                );

                $this->audit->record(
                    action: 'setting.updated',
                    entityType: 'setting',
                    entityId: $setting->id,
                    before: ['key' => $key, 'value' => $before],
                    after: ['key' => $key, 'value' => $value],
                );
            }

            DB::afterCommit(fn () => Cache::forget(DatabaseSettingsReader::CACHE_KEY));
        });
    }

    private function normalize(string $key, mixed $value): string
    {
        $definition = config('settings.catalog')[$key] ?? null;

        if ($definition === null) {
            throw new BusinessRuleException(
                message: "The setting {$key} does not exist.",
                errorCode: 'SETTING_UNKNOWN',
            );
        }

        if ($definition['type'] === 'enum') {
            if (! in_array($value, $definition['options'], true)) {
                throw $this->invalid($key);
            }

            return $value;
        }

        if (! is_numeric($value) || (float) $value < $definition['min'] || (float) $value > $definition['max']) {
            throw $this->invalid($key);
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * El mínimo y el máximo de un tipo de vehículo pueden llegar en lotes
     * distintos: se comparan con lo que quedará guardado, no solo con el lote.
     *
     * @param  array<string, string>  $values
     */
    private function assertConsumptionRanges(array $values): void
    {
        foreach (array_keys(config('fleet.consumption')) as $type) {
            $minKey = "fleet.consumption.{$type}.min";
            $maxKey = "fleet.consumption.{$type}.max";

            if (! isset($values[$minKey]) && ! isset($values[$maxKey])) {
                continue;
            }

            $min = (float) ($values[$minKey] ?? $this->settings->get($minKey));
            $max = (float) ($values[$maxKey] ?? $this->settings->get($maxKey));

            if ($min >= $max) {
                throw new BusinessRuleException(
                    message: "The minimum consumption of {$type} must be below the maximum.",
                    errorCode: 'SETTING_RANGE_INVALID',
                );
            }
        }
    }

    private function invalid(string $key): BusinessRuleException
    {
        return new BusinessRuleException(
            message: "The value of {$key} is not valid.",
            errorCode: 'SETTING_INVALID',
        );
    }
}
