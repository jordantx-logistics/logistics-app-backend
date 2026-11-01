<?php

namespace Src\Settings\Adapters;

use Illuminate\Support\Facades\Cache;
use Src\Settings\Models\Setting;
use Src\Shared\Contracts\SettingsReader;

/**
 * Lee los parámetros de una caché compartida por todos los workers. No guarda
 * nada en el proceso: con Octane, una copia local sobreviviría al cambio.
 */
class DatabaseSettingsReader implements SettingsReader
{
    public const CACHE_KEY = 'settings:values';

    public function get(string $key): mixed
    {
        return $this->stored()[$key] ?? config($key);
    }

    /**
     * @return array<string, string>
     */
    private function stored(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('settings.cache_ttl', 60),
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
