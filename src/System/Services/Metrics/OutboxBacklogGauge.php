<?php

namespace Src\System\Services\Metrics;

use Carbon\Carbon;
use Src\Shared\Models\OutboxEvent;

/**
 * Backlog del outbox: cuántos eventos esperan a que el consumidor los publique
 * (`outbox_pending_total`) y cuánto lleva esperando el más viejo
 * (`outbox_pending_age_seconds`, el que dice si el consumidor está vivo).
 */
final class OutboxBacklogGauge
{
    /**
     * @return array{pending: int, oldest_seconds: int}
     */
    public function sample(): array
    {
        $pending = OutboxEvent::query()->where('status', OutboxEvent::STATUS_PENDING);

        $oldest = (clone $pending)->min('created_at');

        return [
            'pending' => (clone $pending)->count(),
            'oldest_seconds' => $oldest === null
                ? 0
                : max(0, (int) now()->diffInSeconds(Carbon::parse($oldest), true)),
        ];
    }
}
