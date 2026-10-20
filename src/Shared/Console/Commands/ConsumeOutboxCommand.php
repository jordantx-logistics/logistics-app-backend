<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Src\Shared\Exceptions\UnroutableOutboxEvent;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\OutboxEventMap;
use Throwable;

/**
 * Consumidor del outbox (`spec/03-roadmap/fase-01-migracion-mvp.md` §4.3).
 *
 * Cada pasada:
 *  1. devuelve a `pending` los eventos que dejó colgados un consumidor muerto;
 *  2. reclama un lote de `pending` con `available_at <= now()` bajo
 *     `lockForUpdate`, así dos consumidores nunca publican el mismo evento;
 *  3. despacha los jobs que mapea `OutboxEventMap`;
 *  4. marca el evento `processed`, o lo reprograma con backoff exponencial; si
 *     agota los intentos queda `failed`.
 *
 * El evento pasa a `processed` cuando ya está **en la cola**, no cuando el job
 * termina: a partir de ahí los reintentos son cosa del worker (Horizon) y la
 * idempotencia de cada job (`ProcessedEvents`).
 *
 * Un evento sin consumidores registrados se marca `failed`: es un hueco de
 * configuración y tiene que verse, no perderse en silencio.
 */
class ConsumeOutboxCommand extends Command
{
    protected $signature = 'outbox:consume
                            {--limit= : Eventos por pasada (por defecto, `outbox.batch`)}';

    protected $description = 'Publica en las colas los eventos pendientes del outbox.';

    public function handle(OutboxEventMap $map, Dispatcher $bus): int
    {
        if (($recovered = $this->recoverAbandoned()) > 0) {
            Log::warning('outbox.recovered', ['events' => $recovered]);
        }

        $published = 0;
        $failed = 0;

        foreach ($this->claim((int) ($this->option('limit') ?: config('outbox.batch'))) as $event) {
            try {
                $this->publish($event, $map, $bus);
                $this->markProcessed($event);
                $published++;
            } catch (Throwable $error) {
                $this->reschedule($event, $error);
                $failed++;
            }
        }

        $this->info("Eventos publicados: {$published}; fallidos: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Reclama el lote y lo pasa a `processing` antes de despachar nada: es la
     * transacción la que garantiza que otro consumidor no lo vea.
     *
     * @return list<OutboxEvent>
     */
    private function claim(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return DB::transaction(function () use ($limit): array {
            $events = OutboxEvent::query()
                ->where('status', OutboxEvent::STATUS_PENDING)
                ->where(function ($query) {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($events->isEmpty()) {
                return [];
            }

            OutboxEvent::query()
                ->whereIn('id', $events->pluck('id'))
                ->update(['status' => OutboxEvent::STATUS_PROCESSING]);

            return $events->all();
        });
    }

    /**
     * @throws Throwable si el evento no tiene consumidores o el bus no acepta el job
     */
    private function publish(OutboxEvent $event, OutboxEventMap $map, Dispatcher $bus): void
    {
        $jobs = $map->jobsFor($event->event_type);

        if ($jobs === []) {
            throw UnroutableOutboxEvent::forEventType($event->event_type);
        }

        foreach ($jobs as $job) {
            $bus->dispatch(new $job($event->id, $event->payload ?? []));
        }
    }

    private function markProcessed(OutboxEvent $event): void
    {
        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => OutboxEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    private function reschedule(OutboxEvent $event, Throwable $error): void
    {
        $attempts = $event->attempts + 1;

        // Un evento sin consumidores no se reintenta: nace `failed`.
        $exhausted = $error instanceof UnroutableOutboxEvent
            || $attempts >= (int) config('outbox.max_attempts');

        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => $exhausted ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
            'attempts' => $attempts,
            'error' => Str::limit($error->getMessage(), 500),
            'available_at' => $exhausted ? null : now()->addSeconds($this->backoff($attempts)),
        ]);

        Log::warning('outbox.publish_failed', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'attempts' => $attempts,
            'exhausted' => $exhausted,
        ]);
    }

    /**
     * Backoff exponencial sobre la base, con techo (`outbox.backoff`).
     */
    private function backoff(int $attempts): int
    {
        $base = max(1, (int) config('outbox.backoff.base'));
        $cap = max($base, (int) config('outbox.backoff.cap'));

        return (int) min($base * (2 ** ($attempts - 1)), $cap);
    }

    /**
     * Devuelve a `pending` los eventos que un consumidor reclamó y nunca
     * terminó: sin esto se quedarían en `processing` para siempre y el outbox
     * dejaría de publicarlos.
     */
    private function recoverAbandoned(): int
    {
        $timeout = (int) config('outbox.processing_timeout');

        if ($timeout < 1) {
            return 0;
        }

        return OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PROCESSING)
            ->where('updated_at', '<=', now()->subSeconds($timeout))
            ->update([
                'status' => OutboxEvent::STATUS_PENDING,
                'error' => 'Reclamado de nuevo: el consumidor anterior no terminó.',
            ]);
    }
}
