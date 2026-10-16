<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use Src\System\Services\Metrics\MetricsRecorder;

/**
 * Composition root del exporter de Prometheus.
 *
 * Quien conoce la implementación concreta del almacén es este provider; los
 * servicios del módulo `System` solo usan el `CollectorRegistry`.
 */
class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Adapter::class, function (Application $app): Adapter {
            if (! $app['config']->get('metrics.enabled')) {
                return new InMemory;
            }

            if ($app['config']->get('metrics.storage') !== 'redis') {
                return new InMemory;
            }

            $connection = (string) $app['config']->get('metrics.redis_connection');
            $client = $app['redis']->connection($connection)->client();

            // Con phpredis se reutiliza la conexión de Laravel; con Predis (u
            // otro cliente) se deja que el adapter abra la suya desde la config.
            return $client instanceof \Redis
                ? Redis::fromExistingConnection($client)
                : new Redis($this->redisOptions($app, $connection));
        });

        $this->app->singleton(
            CollectorRegistry::class,
            fn (Application $app) => new CollectorRegistry($app->make(Adapter::class)),
        );

        $this->app->singleton(MetricsRecorder::class, fn (Application $app) => new MetricsRecorder(
            $app->make(CollectorRegistry::class),
            (string) $app['config']->get('app.env'),
            (array) $app['config']->get('metrics.buckets'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function redisOptions(Application $app, string $connection): array
    {
        $config = (array) $app['config']->get("database.redis.{$connection}");

        return array_filter([
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 6379,
            'password' => $config['password'] ?? null,
            'database' => $config['database'] ?? 0,
        ], fn ($value) => $value !== null);
    }
}
