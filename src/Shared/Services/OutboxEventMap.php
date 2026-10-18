<?php

namespace Src\Shared\Services;

/**
 * Mapa `event_type` → jobs que lo consumen.
 *
 * El mapa se compone en el composition root (`app/Providers/DomainServiceProvider.php`),
 * que es el único sitio que puede juntar un evento de un contexto con los jobs
 * de otro: `Shared` no importa clases de contexto (ADR-0006).
 *
 * Contrato de cada job del mapa: `__construct(int $outboxEventId, array $payload)`
 * e implementa `ShouldQueue`. La cola la decide el propio job (`onQueue()`),
 * porque un mismo evento puede tener consumidores en colas distintas (el PDF y
 * la proyección de `order.created`, por ejemplo).
 */
final class OutboxEventMap
{
    /**
     * @param  array<string, list<class-string>>  $jobs
     */
    public function __construct(
        private readonly array $jobs = [],
    ) {}

    /**
     * @return list<class-string>
     */
    public function jobsFor(string $eventType): array
    {
        return $this->jobs[$eventType] ?? [];
    }
}
