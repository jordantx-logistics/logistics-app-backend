<?php

namespace Src\Shared\Console\Commands;

use Illuminate\Console\Command;
use Src\Shared\Models\OutboxEvent;

/**
 * Latencia del outbox para la evidencia de cierre de Fase 3.
 *
 * El SLO es `pending` → `processed` con p95 ≤ 5 s. `metrics:collect` ya publica
 * la antigüedad del pendiente más viejo; este comando mide lo que ya se publicó
 * en una ventana, que es lo que pide el criterio.
 */
class OutboxLatencyCommand extends Command
{
    protected $signature = 'outbox:latency
                            {--minutes=15 : Ventana de eventos procesados}
                            {--limit=50000 : Máximo de eventos a leer}';

    protected $description = 'Calcula p50/p95/máx de la latencia de publicación del outbox';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));

        $events = OutboxEvent::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit($limit)
            ->get(['event_type', 'created_at', 'processed_at']);

        if ($events->isEmpty()) {
            $this->info("No hay eventos procesados en los últimos {$minutes} minutos.");

            return self::SUCCESS;
        }

        $latencies = $events
            ->map(fn (OutboxEvent $event): float => $event->created_at->diffInMilliseconds($event->processed_at) / 1000)
            ->sort()
            ->values();

        $count = $latencies->count();

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Eventos', $count],
                ['Ventana (min)', $minutes],
                ['p50 (s)', round($latencies[(int) floor(($count - 1) * 0.50)], 3)],
                ['p95 (s)', round($latencies[(int) floor(($count - 1) * 0.95)], 3)],
                ['p99 (s)', round($latencies[(int) floor(($count - 1) * 0.99)], 3)],
                ['Máx (s)', round($latencies->last(), 3)],
            ],
        );

        return self::SUCCESS;
    }
}
